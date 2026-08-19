<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Utilities;

use Ranetrace\Laravel\Support\InternalLogger;

/**
 * Redacts values stored under sensitive keys before telemetry leaves the host.
 *
 * Applied to log context/extra (and, from the error path, exception context) so
 * that secrets a developer accidentally logs — e.g. `Log::error('x', ['api_key'
 * => $key])` — never reach the Ranetrace backend. Matching is a case-insensitive
 * substring test on the key name. The built-in fragment list is always applied
 * and can be extended (never shrunk) via `ranetrace.scrubbing.extra_keys`.
 */
class SecretScrubber
{
    public const string REDACTION = '[REDACTED]';

    /**
     * Built-in sensitive key fragments (case-insensitive substring match).
     *
     * @var array<int, string>
     */
    private const array DEFAULT_KEYS = [
        'password',
        'passwd',
        'secret',
        'token',
        'api_key',
        'apikey',
        'api-key',
        'authorization',
        'credential',
        'private_key',
        'access_key',
        'signature',
    ];

    /**
     * Extra sensitive fragments applied to ROUTE-PARAMETER NAMES only.
     *
     * `hash` belongs here rather than in {@see DEFAULT_KEYS}: stock Laravel's
     * `email/verify/{id}/{hash}` puts a verification hash in the path, but
     * telemetry elsewhere carries deliberately-hashed, non-secret keys such as
     * `user_agent_hash` and `session_id_hash` which must survive scrubbing.
     *
     * @var array<int, string>
     */
    private const array ROUTE_PARAMETER_KEYS = [
        'hash',
    ];

    /**
     * Characters allowed in the path of a relative reference — RFC 3986 pchar
     * (unreserved, percent-encoded, sub-delims, `:` and `@`) plus the separator
     * itself. Deliberately narrower than "anything without whitespace": it is
     * what keeps prose, JSON and code snippets out of the URL path.
     */
    private const string URL_PATH_CHARS = "A-Za-z0-9._~%!$&'()*+,;=:@/-";

    /**
     * Characters allowed in a query parameter NAME: pchar without `=` (which
     * separates the pair) and without `&` (which separates pairs).
     */
    private const string URL_KEY_CHARS = "A-Za-z0-9._~%!$'()*+,;:@-";

    /**
     * Characters allowed in a query parameter VALUE: as the name, plus `=` and
     * `/`, both legal unencoded in a query.
     */
    private const string URL_VALUE_CHARS = "A-Za-z0-9._~%!$'()*+,;:@=/-";

    /**
     * Recursively redact array values whose key matches a sensitive fragment.
     *
     * Non-array input is returned untouched, so this composes directly with the
     * `mixed` return of {@see DataSanitizer::sanitizeForSerialization()}.
     */
    public static function scrub(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        return self::scrubArray($data, self::sensitiveFragments());
    }

    /**
     * Like {@see scrub()} (key-based redaction), but ALSO scrubs secrets inside
     * URL-shaped string VALUES — catching a secret in an innocuously-keyed URL
     * (e.g. a breadcrumb `data.endpoint` of `https://api/x?token=…`, or the
     * `{token}` segment of a reset link recorded as a navigation breadcrumb)
     * that key-based scrubbing alone would miss.
     *
     * Intended for free-form, untrusted breadcrumb/context data. Composes with
     * the `mixed` return of {@see DataSanitizer::sanitizeForSerialization()},
     * which has already bounded the recursion depth.
     */
    public static function scrubDeep(mixed $data): mixed
    {
        return self::scrubUrlValues(
            self::scrub($data),
            RouteSecretResolver::sensitiveParameterRoutes()
        );
    }

    /**
     * Redact sensitive parameters within a URL's query AND fragment, preserving
     * the scheme, host and path. Non-sensitive params keep their exact
     * encoding; the URL is returned untouched when neither half carries a
     * sensitive param. Use for `url`/`referrer` analytics fields, which can
     * otherwise carry reset tokens, signed-URL signatures, `?api_key=`, etc.
     *
     * The fragment is treated because it is a full-blown leak shape of its own:
     * the OAuth implicit flow returns `#access_token=…&expires_in=…` and never
     * puts it in the query, so a URL with no query at all can still carry the
     * grant. It is only rewritten when it is query-shaped — a run of
     * `key=value` pairs, the same strict test {@see isScrubbableUrl()} applies
     * to a relative reference's query. Any other fragment (a plain anchor, a
     * client-side route) is passed through byte-for-byte; secrets inside a hash
     * ROUTE are the path problem, handled by {@see scrubUrlPath()}.
     */
    public static function scrubUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }

        $fragmentStart = mb_strpos($url, '#');
        $beforeFragment = $fragmentStart === false ? $url : mb_substr($url, 0, $fragmentStart);
        $fragment = $fragmentStart === false ? null : mb_substr($url, $fragmentStart + 1);

        $fragments = self::sensitiveFragments();

        $scrubbedQueryPart = self::scrubUrlQuery($beforeFragment, $fragments);

        // A fragment that is not a run of `key=value` pairs is not a query in
        // disguise, and rewriting it would corrupt it.
        $scrubbedFragment = $fragment !== null && self::isQueryShaped($fragment)
            ? self::scrubQuery($fragment, $fragments)
            : $fragment;

        if ($scrubbedQueryPart === $beforeFragment && $scrubbedFragment === $fragment) {
            return $url;
        }

        return $scrubbedQueryPart.($scrubbedFragment === null ? '' : '#'.$scrubbedFragment);
    }

    /**
     * Whether a route parameter is secret-bearing, judged by its NAME and — for
     * Laravel's custom-key binding syntax `{invitation:token}` — by its BINDING
     * FIELD. The binding field matters because it is where the sensible name
     * lives in that syntax: the parameter is called `invitation`, and only the
     * field (`token`) says what the segment actually holds.
     *
     * Best-effort by nature: this is a substring test over a fragment list, so
     * a parameter named `{code}` or `{t}` is indistinguishable from any other.
     */
    public static function isSensitiveRouteParameter(string $name, ?string $bindingField = null): bool
    {
        $fragments = [...self::sensitiveFragments(), ...self::ROUTE_PARAMETER_KEYS];

        if (self::isSensitive($name, $fragments)) {
            return true;
        }

        return $bindingField !== null
            && $bindingField !== ''
            && self::isSensitive($bindingField, $fragments);
    }

    /**
     * The values of route parameters that {@see isSensitiveRouteParameter()}
     * judges secret-bearing — e.g. the `{token}` of `password/reset/{token}`,
     * the `{hash}` of `email/verify/{id}/{hash}`, or the `{invitation:token}`
     * of a custom-key binding.
     *
     * Secrets in a URL PATH cannot be spotted by inspecting the path itself, so
     * the route definition is used as the oracle: the parameter names say which
     * segments are secret, whatever the (localized or custom) route looks like.
     * Feed the result to {@see scrubPathSegments()} / {@see scrubUrlPath()}.
     * Values that are non-scalar (route-model binding already substituted an
     * object) or empty are skipped.
     *
     * @param  array<array-key, mixed>  $parameters  Raw parameters, ideally from `Route::originalParameters()`.
     * @param  array<array-key, mixed>  $bindingFields  Parameter name => binding field, from `Route::bindingFields()`.
     * @return array<int, string>
     */
    public static function sensitiveRouteParameterValues(array $parameters, array $bindingFields = []): array
    {
        $values = [];

        foreach ($parameters as $name => $value) {
            if (! is_string($name)) {
                continue;
            }

            $bindingField = $bindingFields[$name] ?? null;

            if (! self::isSensitiveRouteParameter($name, is_string($bindingField) ? $bindingField : null)) {
                continue;
            }

            if (! is_scalar($value)) {
                continue;
            }

            $value = (string) $value;

            if ($value !== '') {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * Replace every path segment that (once rawurldecoded) exactly equals one of
     * the given sensitive values with {@see REDACTION}, leaving all other
     * segments byte-for-byte intact. A value occurring in several segments is
     * redacted in each of them.
     *
     * @param  array<int, string>  $sensitiveValues
     */
    public static function scrubPathSegments(string $path, array $sensitiveValues): string
    {
        if ($path === '' || $sensitiveValues === []) {
            return $path;
        }

        $segments = explode('/', $path);

        foreach ($segments as $index => $segment) {
            if ($segment === '') {
                continue;
            }

            if (in_array(rawurldecode($segment), $sensitiveValues, true)) {
                $segments[$index] = self::REDACTION;
            }
        }

        return implode('/', $segments);
    }

    /**
     * Apply {@see scrubPathSegments()} to the PATH component of a URL — and to
     * its FRAGMENT — leaving the scheme, host, port and query exactly as they
     * were, so it composes with {@see scrubUrl()} (which redacts the query)
     * without re-encoding anything.
     *
     * The fragment is treated as a second path because a client-side router
     * keeps its whole route there: `/app#/reset/{token}` puts the secret in the
     * fragment, where the server-side path is merely `/app`. Running the
     * fragment through the same segment matcher is safe on any fragment,
     * sensible or not — it only ever replaces a segment that exactly equals a
     * known sensitive value.
     *
     * @param  array<int, string>  $sensitiveValues
     */
    public static function scrubUrlPath(?string $url, array $sensitiveValues): ?string
    {
        if ($url === null || $url === '' || $sensitiveValues === []) {
            return $url;
        }

        $pathStart = self::pathOffset($url);
        $pathEnd = mb_strlen($url);

        foreach (['?', '#'] as $delimiter) {
            $position = mb_strpos($url, $delimiter, $pathStart);

            if ($position !== false && $position < $pathEnd) {
                $pathEnd = $position;
            }
        }

        $path = mb_substr($url, $pathStart, $pathEnd - $pathStart);
        $scrubbedPath = self::scrubPathSegments($path, $sensitiveValues);

        // Never before $pathEnd: the loop above stops the path AT the `#`.
        $fragmentStart = mb_strpos($url, '#', $pathStart);
        $fragment = $fragmentStart === false ? null : mb_substr($url, $fragmentStart + 1);
        $scrubbedFragment = $fragment === null
            ? null
            : self::scrubPathSegments($fragment, $sensitiveValues);

        if ($scrubbedPath === $path && $scrubbedFragment === $fragment) {
            return $url;
        }

        // Everything between the path and the fragment (the query) is copied
        // through untouched — redacting it is scrubUrl()'s job.
        $betweenLength = ($fragmentStart === false ? mb_strlen($url) : $fragmentStart) - $pathEnd;

        return mb_substr($url, 0, $pathStart)
            .$scrubbedPath
            .mb_substr($url, $pathEnd, $betweenLength)
            .($scrubbedFragment === null ? '' : '#'.$scrubbedFragment);
    }

    /**
     * Redact `key=value` / `key: value` / `key => value` pairs in a free-form
     * string when the key contains a sensitive fragment. Partial, best-effort
     * defense-in-depth for strings we cannot structure (e.g. exception stack
     * traces): it catches query-string-like and key/value leakage, but not
     * positional secret arguments that carry no key.
     */
    public static function scrubString(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $alternation = implode('|', array_map(
            static fn (string $fragment): string => preg_quote($fragment, '/'),
            self::sensitiveFragments()
        ));

        // key-token (containing a sensitive fragment) + separator (= : =>) + value.
        $pattern = '/(["\']?[\w.\-]*(?:'.$alternation.')[\w.\-]*["\']?\s*(?:=>|[:=])\s*)(["\']?)([^"\'\s,;&)}]+)\2/i';

        $result = preg_replace_callback($pattern, static function (array $matches): string {
            return $matches[1].$matches[2].self::REDACTION.$matches[2];
        }, $value);

        // PCRE gave up (backtrack/recursion limit, bad UTF-8): nothing was
        // inspected, so returning the input would hand an UNSCRUBBED string to a
        // caller that believes it was scrubbed. Fail closed — the whole value
        // becomes the placeholder — and log it, so the give-up is never silent.
        if ($result === null) {
            InternalLogger::warning('Scrubbing a free-form string failed; the value was redacted in full', [
                'error' => preg_last_error_msg(),
                'length' => mb_strlen($value, '8bit'),
            ]);

            return self::REDACTION;
        }

        return $result;
    }

    /**
     * Offset at which the path component of a URL starts: after `scheme://host`
     * (and any port) for an absolute URL, after `//host` for a protocol-relative
     * one, or 0 for a relative reference. Returns the string length when the URL
     * has an authority but no path at all (the match then spans the whole
     * string).
     *
     * An authority is only recognised at the START of the string. Searching for
     * `://` anywhere would find the one inside `/reset-password/{token}?next=
     * https://app.test/dashboard` — an unencoded absolute URL in a relative
     * URL's query — and put the "path" inside the query, so the live token in
     * the real path was never inspected.
     */
    private static function pathOffset(string $url): int
    {
        if (preg_match('#^(?:[A-Za-z][A-Za-z0-9+.\-]*:)?//[^/?\#]*#', $url, $matches) !== 1) {
            return 0;
        }

        return mb_strlen($matches[0]);
    }

    /**
     * Redact the sensitive parameters in the QUERY of a fragment-free URL,
     * leaving the rest of it byte-for-byte intact.
     *
     * @param  array<int, string>  $fragments
     */
    private static function scrubUrlQuery(string $url, array $fragments): string
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (! is_string($query) || $query === '') {
            return $url;
        }

        $scrubbed = self::scrubQuery($query, $fragments);
        if ($scrubbed === $query) {
            return $url;
        }

        $queryStart = mb_strpos($url, '?');
        if ($queryStart === false) {
            return $url;
        }

        return mb_substr($url, 0, $queryStart).'?'.$scrubbed;
    }

    /**
     * Redact the values of sensitive keys in a raw query string, leaving every
     * other pair byte-for-byte intact.
     *
     * @param  array<int, string>  $fragments
     */
    private static function scrubQuery(string $query, array $fragments): string
    {
        $pairs = explode('&', $query);

        foreach ($pairs as $index => $pair) {
            if ($pair === '') {
                continue;
            }

            $equals = mb_strpos($pair, '=');
            $rawKey = $equals === false ? $pair : mb_substr($pair, 0, $equals);

            if (self::isSensitive(urldecode($rawKey), $fragments)) {
                $pairs[$index] = $rawKey.'='.self::REDACTION;
            }
        }

        return implode('&', $pairs);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<int, string>  $fragments
     * @return array<array-key, mixed>
     */
    private static function scrubArray(array $data, array $fragments): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitive($key, $fragments)) {
                $data[$key] = self::REDACTION;

                continue;
            }

            if (is_array($value)) {
                $data[$key] = self::scrubArray($value, $fragments);
            }
        }

        return $data;
    }

    /**
     * Recursively redact secrets inside every URL-shaped string value, leaving
     * all other values untouched. Operates on the already-depth-bounded output
     * of {@see DataSanitizer}.
     *
     * Both halves of a URL can carry a secret, so both are treated: the query
     * via {@see scrubUrl()}, and the path via {@see scrubUrlPath()} — the
     * latter only when the application actually defines a route with a
     * secret-bearing parameter. Those candidate routes are resolved once by
     * {@see scrubDeep()} and threaded through this recursion, so a payload with
     * many URL-shaped values walks the route table once, not once per value.
     *
     * @param  array<int, \Illuminate\Routing\Route>  $candidateRoutes
     */
    private static function scrubUrlValues(mixed $data, array $candidateRoutes): mixed
    {
        if (is_string($data)) {
            if (! self::isScrubbableUrl($data)) {
                return $data;
            }

            $scrubbed = self::scrubUrl($data) ?? $data;

            if ($candidateRoutes === []) {
                return $scrubbed;
            }

            return self::scrubUrlPath(
                $scrubbed,
                RouteSecretResolver::forUrl($data, $candidateRoutes)
            ) ?? $scrubbed;
        }

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = self::scrubUrlValues($value, $candidateRoutes);
            }
        }

        return $data;
    }

    /**
     * Whether a string value should be treated as a URL and scrubbed.
     *
     * Absolute http(s) URLs always qualify. A relative reference qualifies only
     * on a strict shape, because these values are free-form: it must carry a
     * path separator or a query, must not contain a character that only turns
     * up in prose, JSON or code, and its query — when it has one — must be a
     * run of `key=value` pairs. That structure is what keeps a JSON payload or
     * a code snippet with a question mark in it out of {@see scrubUrl()}, whose
     * rewrite would otherwise truncate the value at its first `?`.
     */
    private static function isScrubbableUrl(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return true;
        }

        if (preg_match('/[\s"`{}<>\\\\|^\[\]]/', $value) === 1) {
            return false;
        }

        if (! str_contains($value, '/') && ! str_contains($value, '?')) {
            return false;
        }

        [$beforeFragment] = explode('#', $value, 2);
        [$path, $query] = array_pad(explode('?', $beforeFragment, 2), 2, null);

        if (preg_match('#^(?:\.{0,2}/)?['.self::URL_PATH_CHARS.']*$#', $path) !== 1) {
            return false;
        }

        if ($query === null) {
            return true;
        }

        return self::isQueryShaped($query);
    }

    /**
     * Whether a string is a run of `key=value` pairs — the shape a query string
     * has, and the shape an OAuth-style fragment borrows. Shared by
     * {@see isScrubbableUrl()} and {@see scrubUrl()} so both halves of a URL
     * are judged by exactly the same test.
     */
    private static function isQueryShaped(string $value): bool
    {
        $pair = '['.self::URL_KEY_CHARS.']+=['.self::URL_VALUE_CHARS.']*';

        return preg_match('#^'.$pair.'(?:&'.$pair.')*$#', $value) === 1;
    }

    /**
     * @param  array<int, string>  $fragments
     */
    private static function isSensitive(string $key, array $fragments): bool
    {
        $haystack = mb_strtolower($key);

        foreach ($fragments as $fragment) {
            if (str_contains($haystack, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Built-in fragments merged with the user-configured extensions.
     *
     * @return array<int, string>
     */
    private static function sensitiveFragments(): array
    {
        $extra = config('ranetrace.scrubbing.extra_keys', []);

        if (! is_array($extra)) {
            $extra = [];
        }

        $extra = array_map(
            static fn (mixed $fragment): string => mb_strtolower((string) $fragment),
            $extra
        );

        return array_values(array_unique([...self::DEFAULT_KEYS, ...$extra]));
    }
}

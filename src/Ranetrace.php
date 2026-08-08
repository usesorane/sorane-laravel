<?php

declare(strict_types=1);

namespace Ranetrace\Laravel;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Ranetrace\Laravel\Analytics\FingerprintGenerator;
use Ranetrace\Laravel\Events\EventTracker;
use Ranetrace\Laravel\Jobs\HandleErrorJob;
use Ranetrace\Laravel\Jobs\HandleEventJob;
use Ranetrace\Laravel\Support\InternalLogger;
use Ranetrace\Laravel\Utilities\DataSanitizer;
use Ranetrace\Laravel\Utilities\RouteSecretResolver;
use Ranetrace\Laravel\Utilities\SecretScrubber;
use Throwable;

class Ranetrace
{
    /**
     * Truncation suffix appended to fields that exceed their length limit.
     */
    protected const string TRUNCATION_SUFFIX = '... (truncated)';

    /**
     * Per-field caps bound the size of a SINGLE error item. The batch as a whole
     * is kept under the API's 5MB limit by the pre-flight guard in
     * SendBatchToRanetraceJob::trimToByteBudget(). NOT user-tunable: raising any
     * of these widens per-item size and the 413 risk.
     */
    protected const int MAX_MESSAGE_LENGTH = 10_000;

    protected const int MAX_TRACE_LENGTH = 5_000;

    protected const int MAX_FILE_PATH_LENGTH = 500;

    protected const int MAX_URL_LENGTH = 2_000;

    protected const int MAX_SOURCE_FILE_BYTES = 1_048_576; // 1 MB

    protected const int MAX_CONTEXT_LINE_LENGTH = 2_000;

    /**
     * Bounded shape for the `headers` field (now sent as a nested object, not a
     * JSON-encoded string).
     */
    protected const int MAX_HEADER_COUNT = 50;

    protected const int MAX_HEADER_VALUE_LENGTH = 500;

    /**
     * Bounded shape for the `console_arguments` field (now sent as an array,
     * not a JSON-encoded string).
     */
    protected const int MAX_CONSOLE_ARGV_COUNT = 50;

    protected const int MAX_CONSOLE_ARGV_LENGTH = 500;

    /**
     * Request headers considered safe to capture in plaintext. Every other
     * header is masked, so a header carrying a secret that we did not
     * anticipate is masked by default rather than leaked.
     *
     * `x-forwarded-for` is deliberately NOT listed: it carries the client IP
     * chain (PII), and the package's posture is that no IP leaves the host (the
     * analytics path strips it too). It is masked like any other non-allowlisted
     * header.
     *
     * @var array<int, string>
     */
    protected const array SAFE_HEADERS = [
        'accept',
        'accept-charset',
        'accept-encoding',
        'accept-language',
        'cache-control',
        'connection',
        'content-length',
        'content-type',
        'host',
        'referer',
        'user-agent',
        'x-requested-with',
        'x-forwarded-proto',
        'x-forwarded-host',
    ];

    public function report(Throwable $exception): void
    {
        if (! $this->isCaptureEnabled('errors')) {
            return;
        }

        // Never capture an exception that Ranetrace itself threw. The host wires
        // report() into its exception handler, and Laravel's queue worker routes
        // EVERY job exception through that handler — so without this guard a
        // transport failure or internal bug in the package would be reported as
        // one of the customer's application errors and loop back into Ranetrace.
        if ($this->isInternalException($exception)) {
            return;
        }

        // Ranetrace must never throw from its capture path. Losing a single
        // error event is acceptable, breaking the host application is not.
        try {
            $data = $this->buildErrorPayload($exception);

            if (config('ranetrace.errors.queue', true)) {
                HandleErrorJob::dispatch($data);
            } else {
                HandleErrorJob::dispatchSync($data);
            }
        } catch (Throwable $e) {
            InternalLogger::error('Failed to capture exception', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    public function trackEvent(string $eventName, array $properties = [], int|string|null $userId = null, bool $validate = true): void
    {
        if (! $this->isCaptureEnabled('events')) {
            return;
        }

        // Validation stays OUTSIDE the try/catch: an invalid event name is a
        // developer mistake and should fail loudly during development.
        if ($validate) {
            EventTracker::ensureValidEventName($eventName);
        }

        // Everything past validation must never throw into the caller's
        // business logic — fail silently per the package's Core Rule.
        try {
            // getAuthIdentifier() is the safe contract method — works for any
            // Authenticatable, Eloquent or not. Skip the Auth lookup entirely
            // when the caller already provided a userId.
            if ($userId !== null) {
                $user = ['id' => $userId];
            } else {
                $authenticated = Auth::user();
                $user = $authenticated !== null
                    ? ['id' => $authenticated->getAuthIdentifier()]
                    : null;
            }

            $eventData = [
                'event_name' => $eventName,
                'properties' => SecretScrubber::scrubDeep(DataSanitizer::sanitizeForSerialization($properties)),
                'user' => $user,
                'timestamp' => Carbon::now()->toIso8601String(),
                'url' => app()->runningInConsole() ? null : $this->scrubRequestUrl(request()),
                'user_agent_hash' => FingerprintGenerator::generateUserAgentHash(),
                'session_id_hash' => FingerprintGenerator::generateSessionIdHash(),
            ];

            if (config('ranetrace.events.queue', true)) {
                HandleEventJob::dispatch($eventData);
            } else {
                HandleEventJob::dispatchSync($eventData);
            }
        } catch (Throwable $e) {
            InternalLogger::error('Failed to track event', [
                'event_name' => $eventName,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Whether the exception was thrown from inside this package — i.e. it is one
     * of Ranetrace's own operational failures rather than a host application
     * error. Detection is by throw-site file only (getFile()), deliberately NOT
     * by walking the stack trace: the analytics middleware sits in every web
     * request's call stack, so a trace-based check would misclassify ordinary
     * host exceptions as internal and silently stop capturing them.
     */
    private function isInternalException(Throwable $exception): bool
    {
        return str_starts_with($exception->getFile(), __DIR__.DIRECTORY_SEPARATOR);
    }

    /**
     * Determine whether capture is enabled for the given feature: the package
     * must be enabled, the feature itself enabled, and an API key configured.
     */
    private function isCaptureEnabled(string $feature): bool
    {
        return config('ranetrace.enabled', true)
            && config("ranetrace.{$feature}.enabled", true)
            && ! empty(config('ranetrace.key'));
    }

    /**
     * Mask every request header not on the safe-header allowlist AND cap the
     * header count + per-value length. Returns a nested object shape (header
     * name → array of values), which goes on the wire directly (no JSON string
     * encoding).
     *
     * @param  array<string, array<int, string>|string>  $headers
     * @return array<string, array<int, string>>
     */
    private function maskAndBoundHeaders(array $headers): array
    {
        $bounded = [];

        foreach (array_slice($headers, 0, self::MAX_HEADER_COUNT, true) as $name => $values) {
            $values = is_array($values) ? $values : [$values];

            if (! in_array($name, self::SAFE_HEADERS, true)) {
                $bounded[$name] = ['***'];

                continue;
            }

            $bounded[$name] = array_map(
                fn (mixed $v): string => $this->boundHeaderValue($name, $v),
                $values
            );
        }

        return $bounded;
    }

    /**
     * Cast a single header value to string, scrub the Referer (it can carry
     * reset tokens / signed-URL signatures in its query string AND in its path),
     * and truncate to the per-value cap.
     */
    private function boundHeaderValue(string $name, mixed $value): string
    {
        $string = (string) $value;

        if ($name === 'referer') {
            $string = (string) SecretScrubber::scrubUrlPath(
                SecretScrubber::scrubUrl($string),
                RouteSecretResolver::forUrl($string)
            );
        }

        return $this->truncate($string, self::MAX_HEADER_VALUE_LENGTH);
    }

    /**
     * Scrub the URL of the request being handled: sensitive query params, plus
     * the path segments the resolved route names `{token}`, `{hash}`, etc.
     */
    private function scrubRequestUrl(HttpRequest $request): ?string
    {
        return SecretScrubber::scrubUrlPath(
            SecretScrubber::scrubUrl($request->fullUrl()),
            RouteSecretResolver::forRequest($request)
        );
    }

    /**
     * Bound `console_arguments` shape: cap argv count + per-value length.
     * Returns an array (no JSON string encoding).
     *
     * @param  array<int, mixed>  $argv
     * @return array<int, string>
     */
    private function boundConsoleArgv(array $argv): array
    {
        $argv = array_slice($argv, 0, self::MAX_CONSOLE_ARGV_COUNT);

        return array_map(
            fn (mixed $v): string => $this->truncate(SecretScrubber::scrubString((string) $v), self::MAX_CONSOLE_ARGV_LENGTH),
            $argv
        );
    }

    /**
     * Truncate a string to at most $maxLength characters, including the
     * truncation suffix. The final length never exceeds $maxLength.
     */
    private function truncate(string $value, int $maxLength): string
    {
        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        return mb_substr($value, 0, $maxLength - mb_strlen(self::TRUNCATION_SUFFIX)).self::TRUNCATION_SUFFIX;
    }

    /**
     * Strip the application base path so error payloads carry a relative path
     * (app/Http/Controllers/Foo.php) instead of the server's absolute layout
     * (/var/www/app/...). Returns the path unchanged when it is not under
     * base_path() (e.g. a vendor/stub path on some setups).
     */
    private function relativizePath(string $path): string
    {
        if ($path === '') {
            return $path;
        }

        $base = base_path();

        if ($base !== '' && str_starts_with($path, $base)) {
            return mb_ltrim(mb_substr($path, mb_strlen($base)), '/\\');
        }

        return $path;
    }

    /**
     * Shape the authenticated user into the error payload's `user` field.
     *
     * - getAuthIdentifier() is on the Authenticatable contract — safe across
     *   every implementation, Eloquent or not.
     * - getAttribute() returns null when the column is missing, so a host app
     *   whose User model has no `email` does not break error capture. The
     *   method_exists guard covers non-Eloquent custom Authenticatables;
     *   PHPStan doesn't know the host app may not use Eloquent.
     *
     * @return array{id: mixed, email: mixed}|null
     */
    private function buildUserPayload(?Authenticatable $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->getAuthIdentifier(),
            'email' => $this->resolveUserEmail($user),
        ];
    }

    /**
     * Resolve the user's email for the payload, gated by
     * `ranetrace.errors.capture_user_email` (off by default — email is PII).
     * getAttribute() returns null when the column is missing, so a host User
     * model without an `email` column never breaks error capture.
     */
    private function resolveUserEmail(Authenticatable $user): mixed
    {
        if (! config('ranetrace.errors.capture_user_email', false)) {
            return null;
        }

        // @phpstan-ignore function.alreadyNarrowedType
        return method_exists($user, 'getAttribute')
            ? $user->getAttribute('email')
            : null;
    }

    /**
     * Build the error payload sent to Ranetrace.
     *
     * @return array<string, mixed>
     */
    private function buildErrorPayload(Throwable $exception): array
    {
        $request = Request::instance();
        $user = Auth::user();

        $headers = null;
        $url = null;
        $method = null;

        // Determine if the error occurred in a console command
        $isConsole = app()->runningInConsole();

        // If the error occurred via HTTP, gather request data
        if (! $isConsole) {
            $headers = $this->maskAndBoundHeaders($request->headers->all());
            $url = $this->truncate((string) $this->scrubRequestUrl($request), self::MAX_URL_LENGTH);
            $method = $request->method();
        }

        // Get code context (only for readable, reasonably-sized source files)
        $file = $exception->getFile();
        $line = $exception->getLine();
        $context = null;
        $highlightLine = null;

        if (is_readable($file) && filesize($file) < self::MAX_SOURCE_FILE_BYTES) {
            $lines = file($file);
            if (is_array($lines)) {
                $startLine = max(0, $line - 6); // 5 lines before the error line
                // Total 11 lines, each capped so one very long (minified/generated)
                // line can't bloat the payload — keeps the item bounded for the
                // SendBatchToRanetraceJob size guard.
                $contextLines = array_map(
                    fn (string $codeLine): string => $this->capContextLine($codeLine),
                    array_slice($lines, $startLine, 11, true)
                );
                $context = $this->cleanCode(implode('', $contextLines));
                $highlightLine = $line - $startLine; // Relative line to highlight
            }
        }

        // Gather console-specific data if applicable
        $consoleCommand = null;
        $consoleArguments = null;

        if ($isConsole) {
            $consoleCommand = defined('ARTISAN_BINARY')
                ? SecretScrubber::scrubString(implode(' ', $_SERVER['argv'] ?? []))
                : null;
            $consoleArguments = $this->boundConsoleArgv(request()->server('argv') ?? []);
        }

        // Truncate variable-length string fields to per-field caps. Both the
        // message and trace are secret-scrubbed first: an exception message can
        // embed key=value secrets (DB/PDO connection strings, "invalid
        // api_key=…"), and getTraceAsString() can carry them in arg values.
        $message = $this->truncate(SecretScrubber::scrubString($exception->getMessage()), self::MAX_MESSAGE_LENGTH);
        $trace = $this->truncate(SecretScrubber::scrubString($exception->getTraceAsString()), self::MAX_TRACE_LENGTH);

        // Ship a path relative to the app root rather than the absolute server
        // path (avoids leaking /var/www/... layout). Falls back to a LEFT-
        // truncated absolute path (keep the tail) when the file is outside base_path.
        $file = $this->relativizePath($file);

        if (mb_strlen($file) > self::MAX_FILE_PATH_LENGTH) {
            $file = mb_substr($file, -self::MAX_FILE_PATH_LENGTH);
        }

        return [
            'message' => $message,
            'file' => $file,
            'line' => $line,
            'type' => get_class($exception),
            'environment' => config('app.env'),
            'trace' => $trace,
            'headers' => $headers,
            'context' => $context,
            'highlight_line' => $highlightLine,
            'user' => $this->buildUserPayload($user),
            'timestamp' => Carbon::now()->toIso8601String(),
            'url' => $url,
            'method' => $method,
            'php_version' => phpversion(),
            // The generic pair the backend stores as-is; the legacy
            // `laravel_version` spelling (normalized at ingest) is retired.
            'framework' => 'laravel',
            'framework_version' => app()->version(),
            'is_console' => $isConsole,
            'console_command' => $consoleCommand,
            'console_arguments' => $consoleArguments,
        ];
    }

    /**
     * Cap a single source-context line to MAX_CONTEXT_LINE_LENGTH, preserving a
     * trailing newline so the 11-line snippet structure survives. Guards against
     * a minified/generated line bloating the error payload.
     */
    private function capContextLine(string $line): string
    {
        $newline = str_ends_with($line, "\n") ? "\n" : '';
        $content = mb_rtrim($line, "\n");

        if (mb_strlen($content) > self::MAX_CONTEXT_LINE_LENGTH) {
            $content = mb_substr($content, 0, self::MAX_CONTEXT_LINE_LENGTH).self::TRUNCATION_SUFFIX;
        }

        return $content.$newline;
    }

    private function cleanCode(string $code): string
    {
        // Split the code into individual lines
        $lines = explode("\n", $code);

        // Trim each line to remove leading/trailing whitespace
        $trimmedLines = array_map('rtrim', $lines);

        // Find the first line with actual content and determine the minimum indentation
        $minIndent = null;
        foreach ($trimmedLines as $line) {
            if (mb_trim($line) !== '') { // Skip empty lines
                $indent = mb_strlen($line) - mb_strlen(mb_ltrim($line));
                if ($minIndent === null || $indent < $minIndent) {
                    $minIndent = $indent;
                }
            }
        }

        // Remove the minimum indentation from all lines
        if ($minIndent > 0) {
            foreach ($trimmedLines as &$line) {
                if (mb_trim($line) !== '') {
                    $line = mb_substr($line, $minIndent);
                }
            }
        }

        // Rejoin the lines and return the cleaned-up code
        return implode("\n", $trimmedLines);
    }
}

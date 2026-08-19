<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Utilities;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Ranetrace\Laravel\Support\Core;
use Throwable;

/**
 * Resolves the secret-bearing values inside a URL's PATH, using the host
 * application's routes as the oracle.
 *
 * This is what Laravel adds to the shared scrubber and the reason this class
 * stayed behind when `Utilities\SecretScrubber` was deleted: a path segment
 * carries no marker saying "this is a token", only the route definition knows,
 * because it named the segment `{token}`, `{hash}` or `{invitation:token}`, and
 * a framework-agnostic library has no router to ask. Three situations exist:
 *
 * - {@see forRequest()} — the URL belongs to the request being handled, so the
 *   router already resolved and bound its route. Free, and the only lookup that
 *   works for a non-GET route.
 * - {@see forUrl()} — the URL came from somewhere else (a `Referer` header, a
 *   page URL reported by the browser error snippet), so it describes a request
 *   that is not the current one and has no bound route. It must be matched
 *   against the route table separately.
 * - {@see resolver()} — free-form data (breadcrumbs, log context, event
 *   properties) holds many URLs, each describing a different request. That is
 *   the callable the shared scrubber's `$sensitiveValues` seam takes.
 *
 * Feed any of them to `Ranetrace\Php\Support\SecretScrubber`.
 */
final class RouteSecretResolver
{
    /**
     * Sensitive values from the route already bound to this request.
     *
     * `originalParameters()` is used deliberately: by the time this runs,
     * route-model binding may already have swapped a parameter for a model
     * instance, and only the original value still matches the URL segment.
     *
     * @return array<int, string>
     */
    public static function forRequest(Request $request): array
    {
        $route = $request->route();

        if (! $route instanceof Route || ! $route->hasParameters()) {
            return [];
        }

        return Core::scrubber()->sensitiveRouteParameterValues(
            $route->originalParameters(),
            $route->bindingFields()
        );
    }

    /**
     * The per-URL lookup as the shared scrubber's `$sensitiveValues` callable.
     *
     * `scrubDeep()` walks free-form data holding URLs from many requests: a
     * navigation breadcrumb recorded on the previous page, a fetch breadcrumb
     * naming a different endpoint. One shared list would redact the wrong URL's
     * secret, so each is looked up on its own.
     *
     * $alwaysSensitive is unioned into every answer. Callers pass the current
     * request's own values there, because {@see forUrl()} can only match GET
     * routes and the request being handled may well be a POST to
     * `invitations/{token}/accept`. A redactor's answers compose: a segment
     * either lookup calls secret is redacted, so the union can only redact
     * more, never less.
     *
     * The candidate routes are resolved once per resolver and on first use, so
     * a payload with dozens of URL-shaped values walks the route table once and
     * a payload with none never walks it at all.
     *
     * @param  array<int, string>  $alwaysSensitive
     * @return Closure(string): array<int, string>
     */
    public static function resolver(array $alwaysSensitive = []): Closure
    {
        $candidates = null;
        $alwaysSensitive = array_values(array_unique($alwaysSensitive));

        return static function (string $url) use (&$candidates, $alwaysSensitive): array {
            $candidates ??= self::sensitiveParameterRoutes();

            return array_values(array_unique([
                ...$alwaysSensitive,
                ...self::forUrl($url, $candidates),
            ]));
        };
    }

    /**
     * Sensitive values from the route that the given URL would resolve to.
     *
     * A URL with a host is only matched when that host is the application's
     * own: a third-party referrer's path is not described by our routes, so
     * guessing at it would be meaningless. A URL with NO host is a relative
     * reference, which by definition points at this application — the browser
     * resolved it against the page it was on — so it is matched the same way. A
     * host-less URL that still carries a scheme (`mailto:`, `data:`) is not one
     * of our pages and is refused.
     *
     * Matching is restricted to the routes that actually declare a sensitive
     * parameter — usually a handful (password reset, verification, invitations)
     * — so the common case costs a few regex tests rather than a second full
     * pass over the route table. A URL is only ever matched against a CLONE of
     * the route: `Route::bind()` mutates the route it is called on, and these
     * are the same shared instances the current request is using.
     *
     * @param  array<int, Route>|null  $candidateRoutes  Pre-resolved candidates from {@see sensitiveParameterRoutes()}, for a caller resolving many URLs in one pass.
     * @return array<int, string>
     */
    public static function forUrl(?string $url, ?array $candidateRoutes = null): array
    {
        if ($url === null || $url === '') {
            return [];
        }

        try {
            $host = parse_url($url, PHP_URL_HOST);

            if (is_string($host) && $host !== '') {
                if (! self::isApplicationHost($host)) {
                    return [];
                }
            } elseif (parse_url($url, PHP_URL_SCHEME) !== null) {
                return [];
            }

            $request = Request::create($url, 'GET');

            foreach ($candidateRoutes ?? self::routesWithSensitiveParameters() as $route) {
                // Method validation is skipped: the candidate list is already
                // GET-only, and a page URL is a GET by construction.
                if (! $route->matches($request, false)) {
                    continue;
                }

                $bound = (clone $route)->bind($request);

                $values = Core::scrubber()->sensitiveRouteParameterValues(
                    $bound->originalParameters(),
                    $bound->bindingFields()
                );

                if ($values !== []) {
                    return $values;
                }
            }
        } catch (Throwable) {
            // Per the package's Core Rule, a scrubbing failure must never break
            // the host request. Returning no values leaves the URL untouched,
            // matching the behaviour of an app that defines no such route.
            return [];
        }

        return [];
    }

    /**
     * The candidate routes {@see forUrl()} would otherwise resolve per call.
     *
     * {@see resolver()} resolves these once and reuses them for every URL it is
     * asked about, so a payload with dozens of URL-shaped breadcrumb values
     * walks the route table once rather than once per value. An empty result
     * also means path resolution can be skipped outright: an application with
     * no secret-bearing route has nothing for it to find.
     *
     * @return array<int, Route>
     */
    public static function sensitiveParameterRoutes(): array
    {
        try {
            return self::routesWithSensitiveParameters();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The GET routes that declare at least one secret-bearing parameter.
     *
     * A route without one can never contribute a redaction, so it is not worth
     * running its compiled pattern. This narrows the candidate set from every
     * route in the application to (typically) single digits.
     *
     * @return array<int, Route>
     */
    private static function routesWithSensitiveParameters(): array
    {
        $candidates = [];
        $scrubber = Core::scrubber();

        foreach (app(Router::class)->getRoutes()->get('GET') as $route) {
            $bindingFields = $route->bindingFields();

            foreach ($route->parameterNames() as $name) {
                $bindingField = $bindingFields[$name] ?? null;

                if ($scrubber->isSensitiveRouteParameter($name, is_string($bindingField) ? $bindingField : null)) {
                    $candidates[] = $route;

                    break;
                }
            }
        }

        return $candidates;
    }

    /**
     * Whether the host belongs to this application — the current request's host
     * or the configured `app.url` host. Both are consulted because a queue
     * worker or console context has no meaningful request host, while `app.url`
     * is frequently left at its default in local development.
     */
    private static function isApplicationHost(string $host): bool
    {
        $hosts = [];

        $requestHost = request()->getHost();

        if ($requestHost !== '') {
            $hosts[] = $requestHost;
        }

        $configuredHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (is_string($configuredHost) && $configuredHost !== '') {
            $hosts[] = $configuredHost;
        }

        return in_array(
            mb_strtolower($host),
            array_map(static fn (string $known): string => mb_strtolower($known), $hosts),
            true
        );
    }
}

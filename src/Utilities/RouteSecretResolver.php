<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Utilities;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Throwable;

/**
 * Resolves the secret-bearing values inside a URL's PATH, using the host
 * application's routes as the oracle.
 *
 * A path segment carries no marker saying "this is a token" — only the route
 * definition knows, because it named the segment `{token}`, `{hash}` or
 * `{invitation:token}`. Two situations exist, and they need different lookups:
 *
 * - {@see forRequest()} — the URL belongs to the request being handled, so the
 *   router already resolved and bound its route. Free.
 * - {@see forUrl()} — the URL came from somewhere else (a `Referer` header, a
 *   page URL reported by the browser error snippet), so it describes a request
 *   that is not the current one and has no bound route. It must be matched
 *   against the route table separately.
 *
 * Feed either result to {@see SecretScrubber::scrubUrlPath()} /
 * {@see SecretScrubber::scrubPathSegments()}.
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

        return SecretScrubber::sensitiveRouteParameterValues(
            $route->originalParameters(),
            $route->bindingFields()
        );
    }

    /**
     * Sensitive values from the route that the given URL would resolve to.
     *
     * Only the application's own hosts are matched: a third-party referrer's
     * path is not described by our routes, so guessing at it would be
     * meaningless. Matching is restricted to the routes that actually declare a
     * sensitive parameter — usually a handful (password reset, verification,
     * invitations) — so the common case costs a few regex tests rather than a
     * second full pass over the route table. A URL is only ever matched against
     * a CLONE of the route: `Route::bind()` mutates the route it is called on,
     * and these are the same shared instances the current request is using.
     *
     * @return array<int, string>
     */
    public static function forUrl(?string $url): array
    {
        if ($url === null || $url === '') {
            return [];
        }

        try {
            $host = parse_url($url, PHP_URL_HOST);

            if (! is_string($host) || ! self::isApplicationHost($host)) {
                return [];
            }

            $request = Request::create($url, 'GET');

            foreach (self::routesWithSensitiveParameters() as $route) {
                // Method validation is skipped: the candidate list is already
                // GET-only, and a page URL is a GET by construction.
                if (! $route->matches($request, false)) {
                    continue;
                }

                $bound = (clone $route)->bind($request);

                $values = SecretScrubber::sensitiveRouteParameterValues(
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

        foreach (app(Router::class)->getRoutes()->get('GET') as $route) {
            $bindingFields = $route->bindingFields();

            foreach ($route->parameterNames() as $name) {
                $bindingField = $bindingFields[$name] ?? null;

                if (SecretScrubber::isSensitiveRouteParameter($name, is_string($bindingField) ? $bindingField : null)) {
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

<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Support;

use Illuminate\Support\Facades\Auth;
use Ranetrace\Php\Config;

/**
 * This application's Ranetrace configuration, as the shared core reads it.
 *
 * `ranetrace/ranetrace-php`'s `Config` is the seam the shared payload builders
 * ask about the host: the key set deliberately mirrors `config/ranetrace.php`,
 * so the published config array goes straight in. What is added here is what a
 * framework-agnostic SDK has to be told and Laravel can simply answer: the
 * environment, the project root, the framework identity, and how to resolve the
 * current user.
 *
 * Built fresh per capture rather than bound as a singleton: `config()` is
 * mutable at runtime (and every other test changes it), and a snapshot taken at
 * boot would ship a payload describing a configuration the application no longer
 * has.
 */
final class CoreConfig
{
    /**
     * The `framework` half of the pair the error and log payloads carry. Its
     * introduction is what retired the old `laravel_version` key.
     */
    public const string FRAMEWORK = 'laravel';

    public static function make(): Config
    {
        $values = config('ranetrace');

        return new Config([
            ...(is_array($values) ? $values : []),
            'environment' => config('app.env'),
            'project_root' => base_path(),
            'framework' => self::FRAMEWORK,
            'framework_version' => app()->version(),
            'user_resolver' => self::resolveUser(...),
        ]);
    }

    /**
     * The authenticated user as the shared builders want them: an id, and an
     * email the builder is free to drop.
     *
     * - `getAuthIdentifier()` is on the `Authenticatable` contract, so it is
     *   safe across every implementation, Eloquent or not.
     * - The email is only READ when `errors.capture_user_email` is on, so a host
     *   whose accessor is expensive or side-effecting is not made to run it for
     *   a value that would be discarded. The builder gates it again on the same
     *   config value; the two agree by construction.
     * - `getAttribute()` returns null when the column is missing, so a host app
     *   whose User model has no `email` does not break error capture. The
     *   `method_exists` guard covers non-Eloquent custom Authenticatables;
     *   PHPStan doesn't know the host app may not use Eloquent.
     *
     * @return array{id: mixed, email: mixed}|null
     */
    private static function resolveUser(): ?array
    {
        $user = Auth::user();

        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->getAuthIdentifier(),
            // @phpstan-ignore function.alreadyNarrowedType
            'email' => config('ranetrace.errors.capture_user_email', false) && method_exists($user, 'getAttribute')
                ? $user->getAttribute('email')
                : null,
        ];
    }
}

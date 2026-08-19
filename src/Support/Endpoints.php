<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Support;

use Ranetrace\Php\Http\Endpoint;
use Ranetrace\Php\Http\EndpointTable;

/**
 * Where each capture type's batch goes, for this SDK.
 *
 * The four contracted types come from `ranetrace/ranetrace-php` so the paths,
 * the wrapper keys and the User-Agent format cannot drift between the two SDKs.
 * `page_visits` is appended here because website analytics is a Laravel-only
 * feature: it is not in the shared wire contract, and putting it in the shared
 * table would claim a capability the PHP SDK does not have.
 */
final class Endpoints
{
    /**
     * The `{SDK}` segment of every User-Agent this package sends, so a batch can
     * be attributed to this SDK without reading its body.
     */
    public const string SDK = 'Laravel';

    public static function table(): EndpointTable
    {
        return EndpointTable::contract()->with(new Endpoint(
            type: 'page_visits',
            path: '/page-visits/store',
            wrapper: 'page_visits',
            feature: 'PageVisits',
            timeoutKey: 'website_analytics.timeout',
        ));
    }

    /**
     * The endpoint's timeout as a key of THIS application's config. The shared
     * table stores it relative to the SDK's own config root, which for a Laravel
     * app is the `ranetrace` namespace.
     */
    public static function timeoutKey(Endpoint $endpoint): string
    {
        return 'ranetrace.'.$endpoint->timeoutKey;
    }
}

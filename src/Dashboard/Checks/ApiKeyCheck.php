<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Dashboard\Checks;

/**
 * Without an ingest key, capture is silently disabled — the single most common
 * "nothing is arriving" cause. Critical.
 *
 * Deliberately only about `RANETRACE_KEY`. The MCP token is a separate
 * credential with the opposite job (reading data back out) and it is held by
 * the MCP client, not by this application, so there is nothing here to check.
 */
class ApiKeyCheck implements Check
{
    public function run(array $status): CheckResult
    {
        $configured = (bool) ($status['config']['api_key_configured'] ?? false);

        if ($configured) {
            return CheckResult::pass('api_key', 'Ingest API key is configured');
        }

        return CheckResult::fail(
            'api_key',
            'Ingest API key is missing',
            'Set RANETRACE_KEY in .env. Without it, captured data is buffered but never sent. '
                .'This is the ingest key; the MCP tools use a separate token, held by your MCP client.'
        );
    }
}

<?php

declare(strict_types=1);

use Ranetrace\Laravel\Tests\TestCase;

uses(TestCase::class)->in('Browser', 'Contract', 'Feature', 'Unit');

/**
 * The client's answer when a token-bound MCP method is called without a
 * credential, mirroring RanetraceApiClient::mcpTokenRequiredMessage(null).
 * One copy here so the ~25 missing-credential assertions do not each carry
 * the full sentence.
 */
function missingMcpTokenMessage(): string
{
    return 'This endpoint requires a Ranetrace MCP token.'
        ." Create one on the website's /mcp page in Ranetrace."
        ." Set it as RANETRACE_MCP_TOKEN in your MCP client's server entry (or in .env), then restart the MCP server.";
}

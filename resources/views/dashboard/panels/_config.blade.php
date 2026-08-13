@php
    $cfg = $status['config'] ?? [];
    $enabled = $cfg['enabled'] ?? false;
    $keyConfigured = $cfg['api_key_configured'] ?? false;
    $mcpTokenConfigured = $cfg['mcp_token_configured'] ?? false;
    $features = [
        'errors' => 'ranetrace.errors.enabled',
        'events' => 'ranetrace.events.enabled',
        'logging' => 'ranetrace.logging.enabled',
        'website_analytics' => 'ranetrace.website_analytics.enabled',
        'javascript_errors' => 'ranetrace.javascript_errors.enabled',
        'mcp' => 'ranetrace.mcp.enabled',
    ];
@endphp
<section class="rt-panel">
    <div class="rt-panel__head">
        <h2 class="rt-panel__title">Configuration</h2>
    </div>
    <div class="rt-panel__body">
        <div class="rt-kv">
            <span class="rt-kv__key">Capture enabled</span>
            <span class="rt-kv__val">
                <span class="rt-pill rt-pill--{{ $enabled ? 'ok' : 'muted' }}">{{ $enabled ? 'Enabled' : 'Disabled' }}</span>
            </span>
        </div>
        <div class="rt-kv">
            <span class="rt-kv__key">Ingest API key</span>
            <span class="rt-kv__val">
                <span class="rt-pill rt-pill--{{ $keyConfigured ? 'ok' : 'bad' }}">{{ $keyConfigured ? 'Configured' : 'Missing' }}</span>
            </span>
        </div>
        {{-- The MCP token is a separate credential and is *expected* to be absent
             in production, so a missing one is muted rather than flagged bad. --}}
        <div class="rt-kv">
            <span class="rt-kv__key">MCP token</span>
            <span class="rt-kv__val">
                <span class="rt-pill rt-pill--{{ $mcpTokenConfigured ? 'ok' : 'muted' }}">{{ $mcpTokenConfigured ? 'Configured' : 'Not set' }}</span>
            </span>
        </div>
        <div class="rt-kv">
            <span class="rt-kv__key">Cache driver</span>
            <span class="rt-kv__val rt-kv__val--mono">{{ $cfg['cache_driver'] ?? '—' }}</span>
        </div>
        <div class="rt-kv">
            <span class="rt-kv__key">Batch queue</span>
            <span class="rt-kv__val rt-kv__val--mono">{{ $cfg['queue_name'] ?? '—' }}</span>
        </div>

        @foreach ($features as $name => $configKey)
            <div class="rt-kv">
                <span class="rt-kv__key">{{ \Illuminate\Support\Str::headline($name) }}</span>
                <span class="rt-kv__val">
                    @php($on = (bool) config($configKey))
                    <span class="rt-pill rt-pill--{{ $on ? 'ok' : 'muted' }}">{{ $on ? 'On' : 'Off' }}</span>
                </span>
            </div>
        @endforeach
    </div>
</section>

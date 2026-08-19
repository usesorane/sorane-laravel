<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Ranetrace\Laravel\Jobs\BaseRanetraceJob;
use Ranetrace\Laravel\Jobs\SendBatchToRanetraceJob;
use Ranetrace\Laravel\Services\RanetraceApiClient;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Laravel\Services\RanetracePauseManager;
use Ranetrace\Php\Contract\WireContract;

/**
 * The envelope around an item: which URL it goes to, which key it is wrapped
 * under, which headers travel with it, how much of it may travel at once, and
 * what the client does with each answer.
 *
 * The paths and wrapper keys are inline strings in RanetraceApiClient, so the
 * assertions drive the real batch job rather than reading those strings back:
 * that way the buffer type string, the endpoint and the wrapper key are checked
 * as one chain, which is how a mismatch actually reaches production.
 */
beforeEach(function (): void {
    Config::set('ranetrace.key', 'test-api-key-12345');
    Config::set('ranetrace.batch.cache_driver', 'array');
    Cache::store('array')->flush();
});

/**
 * Headers Guzzle itself puts on the wire. They are transport bookkeeping rather
 * than anything this SDK chose, so the contract does not name them and the
 * header assertions below subtract them before comparing.
 *
 * @var list<string>
 */
const CONTRACT_TRANSPORT_HEADERS = ['Host', 'Content-Length'];

/**
 * The `{Feature}` segment of the User-Agent, per item type.
 *
 * headers.json describes the format and names the four features in prose
 * rather than as a machine-readable map, and no casing rule turns
 * `javascript_errors` into `JavaScriptErrors`, so the mapping is spelled out
 * here and the surrounding format is taken from the fixture.
 *
 * @var array<string, string>
 */
const CONTRACT_USER_AGENT_FEATURES = [
    'errors' => 'Errors',
    'events' => 'Events',
    'logs' => 'Logs',
    'javascript_errors' => 'JavaScriptErrors',
];

/**
 * Send one buffered item of $type through the real batch job and return the
 * request that left the client.
 */
function contractSentBatchRequest(string $type): Request
{
    Cache::store('array')->flush();

    Http::fake(['api.ranetrace.com/*' => Http::response(['success' => true], 200)]);

    $buffer = app(RanetraceBatchBuffer::class);
    $buffer->addItem($type, WireContract::item($type)['examples']['minimal']);

    (new SendBatchToRanetraceJob($type, 10))->handle(
        app(RanetraceApiClient::class),
        $buffer,
        app(RanetracePauseManager::class)
    );

    $sent = null;

    Http::assertSent(function (Request $request) use (&$sent): bool {
        $sent = $request;

        return true;
    });

    return $sent;
}

/**
 * The headers this SDK set on a request, with Guzzle's own removed.
 *
 * @return array<string, string>
 */
function contractSdkHeaders(Request $request): array
{
    $headers = [];

    foreach ($request->headers() as $name => $values) {
        if (in_array($name, CONTRACT_TRANSPORT_HEADERS, true)) {
            continue;
        }

        $headers[$name] = (string) ($values[0] ?? '');
    }

    return $headers;
}

function contractBatchJobConstant(string $name): mixed
{
    return (new ReflectionClass(SendBatchToRanetraceJob::class))->getConstant($name);
}

test('a batch goes to the path the contract names for its type', function (string $type): void {
    $endpoint = WireContract::endpoints()['endpoints'][$type];
    $baseUrl = WireContract::endpoints()['base_url_default'];

    expect(contractSentBatchRequest($type)->url())->toBe($baseUrl.$endpoint['path']);
})->with(WireContract::itemTypes());

test('a batch body is a single wrapper key holding a plain array of items', function (string $type): void {
    $wrapper = WireContract::endpoints()['endpoints'][$type]['wrapper'];
    $body = contractSentBatchRequest($type)->data();

    expect($wrapper)->toBe(WireContract::envelope()['wrappers'][$type])
        ->and(array_keys($body))->toBe([$wrapper])
        ->and($body[$wrapper])->toBeArray()
        ->and(array_keys($body[$wrapper]))->toBe([0]);
})->with(WireContract::itemTypes());

test('a batch travels with exactly the five contracted headers', function (string $type): void {
    $contract = WireContract::headers();
    $sent = contractSdkHeaders(contractSentBatchRequest($type));

    $userAgent = str_replace(
        ['{SDK}', '{Feature}', '{version}'],
        ['Laravel', CONTRACT_USER_AGENT_FEATURES[$type], $contract['api_version']],
        $contract['request']['User-Agent']['format']
    );

    expect(array_keys($sent))->toEqualCanonicalizing(array_keys($contract['request']))
        ->and($sent['Content-Type'])->toBe($contract['request']['Content-Type']['value'])
        ->and($sent['Accept'])->toBe($contract['request']['Accept']['value'])
        ->and($sent['Ranetrace-API-Version'])->toBe($contract['request']['Ranetrace-API-Version']['value'])
        ->and($sent['Ranetrace-API-Version'])->toBe($contract['api_version'])
        ->and($sent['Authorization'])->toBe(str_replace('{api key}', 'test-api-key-12345', $contract['request']['Authorization']['format']))
        ->and($sent['User-Agent'])->toBe($userAgent);
})->with(WireContract::itemTypes());

test('the batch budgets equal the envelope contract', function (): void {
    $envelope = WireContract::envelope();

    $maxItems = (new ReflectionMethod(SendBatchToRanetraceJob::class, 'getMaxBatchSize'))
        ->invoke(new SendBatchToRanetraceJob('errors'));

    expect($maxItems)->toBe($envelope['max_items_per_batch'])
        ->and(contractBatchJobConstant('MAX_BATCH_BYTES'))->toBe($envelope['client_trim_bytes'])
        ->and($envelope['client_trim_bytes'])->toBeLessThan($envelope['server_max_body_bytes']);
});

/**
 * The per-item budget lives on the capture jobs rather than on the batch job:
 * an item is held to it once, where it is captured, so a single oversize item
 * cannot reach a batch it would then poison.
 */
test('the per-item budgets equal the envelope contract', function (): void {
    $policy = WireContract::envelope()['client_item_policy'];

    $maxItemFieldBytes = (new ReflectionClass(BaseRanetraceJob::class))->getConstant('MAX_ITEM_FIELD_BYTES');

    expect(BaseRanetraceJob::MAX_ITEM_BYTES)->toBe($policy['max_item_bytes'])
        ->and($maxItemFieldBytes)->toBe($policy['max_item_field_bytes'])
        ->and($policy['never_send_marker_key'])->toBeTrue();
});

/**
 * The rows that end the batch here and now: the pause is set on the same run
 * the status arrives. `rebuffer` and `drop` are one decision read two ways, so
 * both fixture columns are checked against the same buffer count.
 */
test('a terminal response pauses the scope and length the contract declares', function (string $status): void {
    $this->freezeTime();

    $row = WireContract::responses()['statuses'][$status];

    Http::fake(['api.ranetrace.com/*' => Http::response(['error' => ['message' => 'nope']], (int) $status)]);

    $buffer = app(RanetraceBatchBuffer::class);
    $buffer->addItem('errors', ['message' => 'boom']);

    $pauseManager = app(RanetracePauseManager::class);
    (new SendBatchToRanetraceJob('errors', 10))->handle(app(RanetraceApiClient::class), $buffer, $pauseManager);

    $pause = $row['pause_scope'] === 'global'
        ? $pauseManager->getGlobalPause()
        : $pauseManager->getFeaturePause('errors');

    expect($pause)->not->toBeNull()
        ->and(Carbon::parse($pause['paused_until'])->timestamp - now()->timestamp)->toBe($row['pause_seconds'])
        ->and($buffer->count('errors'))->toBe($row['rebuffer'] === true ? 1 : 0)
        ->and($buffer->count('errors') === 0)->toBe($row['drop']);
})->with(['401', '403', '413', '422']);

/**
 * The one row whose pause length is not a constant: it comes from the response.
 * A missing Retry-After is stored as an empty string and casts to zero, so
 * `rate_limit_floor_seconds` is what stops a rate-limited client hammering the
 * endpoint on every run, which is why the fixture records it as part of the row.
 *
 * The fixture's prose calls it a floor. Both SDKs apply it the same, narrower
 * way: as the substitute for an absent or non-positive header, not as a lower
 * bound on a small positive one. The cases below are therefore the ones the two
 * implementations actually agree on rather than the ones the wording implies.
 *
 * @param  int|null  $retryAfter  The header the endpoint sent, or null for none.
 */
test('a rate limit pauses for Retry-After, substituting the contract floor when it is absent', function (?int $retryAfter, string $expected): void {
    $this->freezeTime();

    $responses = WireContract::responses();
    $floor = $responses['rate_limit_floor_seconds'];

    expect($responses['statuses']['429']['pause_seconds_floor'])->toBe($floor)
        ->and($responses['statuses']['429']['pause_seconds'])->toBeNull();

    Http::fake([
        'api.ranetrace.com/*' => Http::response(
            [],
            429,
            $retryAfter === null ? [] : ['Retry-After' => (string) $retryAfter]
        ),
    ]);

    $pauseManager = app(RanetracePauseManager::class);
    $buffer = app(RanetraceBatchBuffer::class);
    $buffer->addItem('errors', ['message' => 'boom']);

    (new SendBatchToRanetraceJob('errors', 10))->handle(app(RanetraceApiClient::class), $buffer, $pauseManager);

    $seconds = Carbon::parse($pauseManager->getFeaturePause('errors')['paused_until'])->timestamp - now()->timestamp;

    expect($seconds)->toBe($expected === 'header' ? $retryAfter : $floor)
        ->and($buffer->count('errors'))->toBe(1);
})->with([
    'the endpoint named a pause length' => [120, 'header'],
    'the endpoint sent no Retry-After' => [null, 'floor'],
    'the endpoint sent a zero Retry-After' => [0, 'floor'],
]);

/**
 * The transient rows are where the two SDKs' designs part company. The
 * file-based worker in ranetrace/ranetrace-php pauses on the spot, because its
 * next run is its retry; this queue-based job spends its retry envelope first
 * (release() with the 60/300/900s backoff) and only pauses once that is
 * exhausted. What is shared, and what the contract fixes, is the length of the
 * pause it lands on, so the assertion exhausts the envelope rather than
 * expecting the worker's shape.
 */
test('an exhausted retry envelope pauses for the contract default length', function (int $status): void {
    $this->freezeTime();

    $responses = WireContract::responses();
    $row = $status === 0 ? $responses['statuses']['network'] : $responses['statuses']['default'];

    Http::fake($status === 0
        ? ['api.ranetrace.com/*' => fn () => throw new ConnectionException('dead')]
        : ['api.ranetrace.com/*' => Http::response(['error' => ['message' => 'nope']], $status)]);

    $buffer = app(RanetraceBatchBuffer::class);
    $buffer->addItem('errors', ['message' => 'boom']);

    $pauseManager = app(RanetracePauseManager::class);

    $job = new class('errors', 10) extends SendBatchToRanetraceJob
    {
        /**
         * Stand at the end of the retry envelope. Nothing else about the job is
         * changed: attempts() reads the queue's own attempt counter, which is
         * absent when the job is invoked directly.
         */
        public function attempts(): int
        {
            return $this->tries;
        }
    };

    $job->handle(app(RanetraceApiClient::class), $buffer, $pauseManager);

    expect($row['pause_scope'])->toBe('feature')
        ->and(Carbon::parse($pauseManager->getFeaturePause('errors')['paused_until'])->timestamp - now()->timestamp)
        ->toBe($responses['pause_seconds_default'])
        ->and($row['pause_seconds'])->toBe($responses['pause_seconds_default'])
        ->and($buffer->count('errors'))->toBe($row['rebuffer'] === true ? 1 : 0);
})->with([500, 503, 0]);

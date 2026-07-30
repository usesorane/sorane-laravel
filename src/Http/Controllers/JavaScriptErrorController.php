<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Ranetrace\Laravel\Analytics\FingerprintGenerator;
use Ranetrace\Laravel\Jobs\HandleJavaScriptErrorJob;
use Ranetrace\Laravel\Support\InternalLogger;
use Ranetrace\Laravel\Utilities\DataSanitizer;
use Ranetrace\Laravel\Utilities\PayloadSizer;
use Ranetrace\Laravel\Utilities\RouteSecretResolver;
use Ranetrace\Laravel\Utilities\SecretScrubber;
use Throwable;

class JavaScriptErrorController extends Controller
{
    /**
     * Default ignored-error message patterns. Used as the in-code fallback so a
     * published config that removed the `ignored_errors` key restores these
     * defaults rather than silently un-filtering noise. Mirrors
     * config/ranetrace.php → javascript_errors.ignored_errors.
     *
     * @var array<int, string>
     */
    public const array DEFAULT_IGNORED_ERRORS = [
        'ResizeObserver loop limit exceeded',
        'ResizeObserver loop completed with undelivered notifications',
        'Script error.',
        'Script error',
        'Failed to fetch',
        'NetworkError when attempting to fetch resource',
        'Network request failed',
        'Load failed',
        'Loading chunk',
        'ChunkLoadError',
        'cancelled',
        'canceled',
        'The operation was aborted',
        'AbortError',
        'Illegal invocation',
    ];

    /**
     * Maximum serialized context size (JSON-encoded bytes). Oversize context is
     * replaced with a truncation marker rather than truncated mid-structure.
     */
    private const int MAX_CONTEXT_BYTES = 51_200; // 50 KB

    /**
     * Maximum serialized data size per breadcrumb (JSON-encoded bytes).
     */
    private const int MAX_BREADCRUMB_DATA_BYTES = 5_120; // 5 KB

    public function store(Request $request): JsonResponse
    {
        // The JS error endpoint is part of the capture path and must never
        // throw uncaught into the host app, even if sanitization/dispatch
        // fails. (Failure-isolation Core Rule.)
        try {
            return $this->process($request);
        } catch (Throwable $e) {
            InternalLogger::error('Failed to process JavaScript error', [
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process error',
            ], 500);
        }
    }

    /**
     * Cap breadcrumb count and per-breadcrumb data size.
     *
     * Required breadcrumb fields (timestamp/category/message) are guaranteed
     * present by the validator; no fallback defaults are applied here.
     *
     * @param  array<int, array<string, mixed>>  $breadcrumbs
     * @return array<int, array<string, mixed>>
     */
    protected function sanitizeBreadcrumbs(array $breadcrumbs): array
    {
        $maxBreadcrumbs = config('ranetrace.javascript_errors.max_breadcrumbs', 20);

        // Keep the most recent N breadcrumbs (oldest dropped)
        $breadcrumbs = array_slice($breadcrumbs, -$maxBreadcrumbs);

        return array_map(function (array $breadcrumb): array {
            $data = PayloadSizer::capBytes(
                SecretScrubber::scrubDeep(DataSanitizer::sanitizeForSerialization($breadcrumb['data'] ?? [])),
                self::MAX_BREADCRUMB_DATA_BYTES,
                'Breadcrumb data exceeded 5KB limit and was removed'
            );

            return [
                'timestamp' => $breadcrumb['timestamp'],
                'category' => $breadcrumb['category'],
                'message' => $breadcrumb['message'],
                'data' => $data,
            ];
        }, $breadcrumbs);
    }

    private function process(Request $request): JsonResponse
    {
        if (! config('ranetrace.enabled', true)) {
            return response()->json([
                'success' => false,
                'message' => 'Ranetrace is not enabled',
            ], 403);
        }

        if (! config('ranetrace.javascript_errors.enabled', false)) {
            return response()->json([
                'success' => false,
                'message' => 'JavaScript error tracking is not enabled',
            ], 403);
        }

        // Apply Referer fallback BEFORE validation so the validator is the
        // single source of truth that `url` is present.
        if (blank($request->input('url'))) {
            $request->merge(['url' => $request->header('Referer')]);
        }

        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:2000',
            'stack' => 'nullable|string|max:10000',
            'type' => 'nullable|string|max:100',
            'filename' => 'nullable|string|max:500',
            'line' => 'nullable|integer',
            'column' => 'nullable|integer',
            'url' => 'required|string|max:2000',
            'timestamp' => 'nullable|string',
            'breadcrumbs' => 'nullable|array',
            'breadcrumbs.*.timestamp' => 'required|string',
            'breadcrumbs.*.category' => 'required|string|max:100',
            'breadcrumbs.*.message' => 'required|string|max:500',
            'breadcrumbs.*.data' => 'nullable|array',
            'context' => 'nullable|array',
            'browser_info' => 'nullable|array',
            'browser_info.screen_width' => 'nullable|numeric',
            'browser_info.screen_height' => 'nullable|numeric',
            'browser_info.viewport_width' => 'nullable|numeric',
            'browser_info.viewport_height' => 'nullable|numeric',
            'browser_info.device_memory' => 'nullable|numeric',
            'browser_info.hardware_concurrency' => 'nullable|numeric',
            'browser_info.connection_type' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $ignoredErrors = config('ranetrace.javascript_errors.ignored_errors', self::DEFAULT_IGNORED_ERRORS);
        $errorMessage = $request->input('message');

        foreach ($ignoredErrors as $pattern) {
            if (mb_stripos($errorMessage, $pattern) !== false) {
                return response()->json([
                    'success' => true,
                    'message' => 'Error ignored based on pattern',
                ], 200);
            }
        }

        $sampleRate = config('ranetrace.javascript_errors.sample_rate', 1.0);
        if ($sampleRate < 1.0 && mt_rand() / mt_getrandmax() > $sampleRate) {
            return response()->json([
                'success' => true,
                'message' => 'Error sampled out',
            ], 200);
        }

        // Sanitize, redact secret-keyed values, then cap size (oversize objects
        // are replaced with a marker — truncating mid-structure yields invalid JSON).
        $context = PayloadSizer::capBytes(
            SecretScrubber::scrubDeep(DataSanitizer::sanitizeForSerialization($request->input('context', []))),
            self::MAX_CONTEXT_BYTES,
            'Context exceeded 50KB limit and was removed'
        );

        $reportedUrl = (string) $request->input('url');

        $errorData = [
            'message' => $errorMessage,
            'stack' => $request->input('stack') !== null
                ? SecretScrubber::scrubString((string) $request->input('stack'))
                : null,
            'type' => $request->input('type', 'Error'),
            'filename' => $request->input('filename'),
            'line' => $request->input('line'),
            'column' => $request->input('column'),
            'user_agent' => $request->userAgent(),
            // The reported URL is the page the error happened on, NOT this POST
            // endpoint, so the current route says nothing about it — it gets its
            // own route lookup to redact `{token}`-style path segments.
            'url' => SecretScrubber::scrubUrlPath(
                SecretScrubber::scrubUrl($reportedUrl),
                RouteSecretResolver::forUrl($reportedUrl)
            ),
            'timestamp' => $request->input('timestamp', now()->format('c')),
            'environment' => config('app.env'),
            // Contract method on Authenticatable — safe for non-Eloquent user models.
            'user_id' => $request->user()?->getAuthIdentifier(),
            // Hashed (not raw) so a leaked payload can't be used to hijack the
            // session, while still grouping errors within the same session.
            'session_id' => FingerprintGenerator::hash(session()->getId()),
            'breadcrumbs' => $this->sanitizeBreadcrumbs($request->input('breadcrumbs', [])),
            'context' => $context,
            'browser_info' => [
                'screen_width' => $request->input('browser_info.screen_width'),
                'screen_height' => $request->input('browser_info.screen_height'),
                'viewport_width' => $request->input('browser_info.viewport_width'),
                'viewport_height' => $request->input('browser_info.viewport_height'),
                'device_memory' => $request->input('browser_info.device_memory'),
                'hardware_concurrency' => $request->input('browser_info.hardware_concurrency'),
                'connection_type' => $request->input('browser_info.connection_type'),
            ],
        ];

        if (config('ranetrace.javascript_errors.queue', true)) {
            HandleJavaScriptErrorJob::dispatch($errorData);
        } else {
            HandleJavaScriptErrorJob::dispatchSync($errorData);
        }

        return response()->json([
            'success' => true,
            'message' => 'Error received',
        ], 200);
    }
}

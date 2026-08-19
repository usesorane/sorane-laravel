<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Ranetrace\Laravel\Analytics\FingerprintGenerator;
use Ranetrace\Laravel\Jobs\HandleJavaScriptErrorJob;
use Ranetrace\Laravel\Support\CoreConfig;
use Ranetrace\Laravel\Support\InternalLogger;
use Ranetrace\Laravel\Utilities\CoreScrubber;
use Ranetrace\Laravel\Utilities\RouteSecretResolver;
use Ranetrace\Php\JavaScript\ErrorItemBuilder;
use Throwable;

/**
 * The endpoint the browser capture script posts to.
 *
 * The fifteen-key item, its caps and its breadcrumb rules live in
 * `Ranetrace\Php\JavaScript\ErrorItemBuilder`, shared with
 * `ranetrace/ranetrace-php`. What stays here is the Laravel half: the config
 * gates, the validator, the ignore and sample filters, and the four fields a
 * browser must never be trusted to state, which this application observes for
 * itself.
 */
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
            'timestamp' => 'nullable|string|max:64',
            'breadcrumbs' => 'nullable|array',
            'breadcrumbs.*.timestamp' => 'required|string|max:64',
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

        $reportedUrl = (string) $request->input('url');

        // Contract method on Authenticatable — safe for non-Eloquent user
        // models, and typed `mixed` because a host may key users any way it
        // likes. Only a scalar identifier is shippable as `user_id`.
        $userId = $request->user()?->getAuthIdentifier();

        $errorData = (new ErrorItemBuilder(CoreConfig::make(), new CoreScrubber))->build(
            payload: $request->all(),
            userAgent: $request->userAgent(),
            userId: is_int($userId) || is_string($userId) ? $userId : null,
            // Hashed (not raw) so a leaked payload can't be used to hijack the
            // session, while still grouping errors within the same session.
            sessionId: FingerprintGenerator::hash(session()->getId()),
            // The reported URL is the page the error happened on, NOT this POST
            // endpoint, so the current route says nothing about it — it gets its
            // own route lookup to redact `{token}`-style path segments.
            sensitivePathValues: RouteSecretResolver::forUrl($reportedUrl),
            // Carbon rather than the builder's own clock, so a host (or a test)
            // that freezes time sees the frozen value.
            timestampFallback: now()->format('c'),
        );

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

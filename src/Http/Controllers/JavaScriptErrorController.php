<?php

namespace Sorane\ErrorReporting\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Sorane\ErrorReporting\Jobs\SendJavaScriptErrorToSoraneJob;
use Sorane\ErrorReporting\Utilities\DataSanitizer;

class JavaScriptErrorController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        // Check if JavaScript error tracking is enabled
        if (! config('sorane.javascript_errors.enabled', false)) {
            return response()->json([
                'success' => false,
                'message' => 'JavaScript error tracking is not enabled',
            ], 403);
        }

        // Validate the incoming error data
        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:2000',
            'stack' => 'nullable|string|max:10000',
            'type' => 'nullable|string|max:100',
            'filename' => 'nullable|string|max:500',
            'line' => 'nullable|integer',
            'column' => 'nullable|integer',
            'url' => 'nullable|string|max:2000',
            'timestamp' => 'nullable|string',
            'breadcrumbs' => 'nullable|array',
            'breadcrumbs.*.timestamp' => 'required|string',
            'breadcrumbs.*.category' => 'required|string|max:100',
            'breadcrumbs.*.message' => 'required|string|max:500',
            'breadcrumbs.*.data' => 'nullable|array',
            'context' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Check if error should be ignored based on patterns
        $ignoredErrors = config('sorane.javascript_errors.ignored_errors', []);
        $errorMessage = $request->input('message');

        foreach ($ignoredErrors as $pattern) {
            if (stripos($errorMessage, $pattern) !== false) {
                return response()->json([
                    'success' => true,
                    'message' => 'Error ignored based on pattern',
                ], 200);
            }
        }

        // Apply sample rate
        $sampleRate = config('sorane.javascript_errors.sample_rate', 1.0);
        if ($sampleRate < 1.0 && mt_rand() / mt_getrandmax() > $sampleRate) {
            return response()->json([
                'success' => true,
                'message' => 'Error sampled out',
            ], 200);
        }

        // Prepare error data for Sorane API
        $errorData = [
            'message' => $errorMessage,
            'stack' => $request->input('stack'),
            'type' => $request->input('type', 'Error'),
            'filename' => $request->input('filename'),
            'line' => $request->input('line'),
            'column' => $request->input('column'),
            'user_agent' => $request->userAgent(),
            'url' => $request->input('url', $request->header('Referer')),
            'timestamp' => $request->input('timestamp', now()->format('c')),
            'environment' => config('app.env'),
            'user_id' => $request->user()?->id,
            'session_id' => session()->getId(),
            'breadcrumbs' => $this->sanitizeBreadcrumbs($request->input('breadcrumbs', [])),
            'context' => DataSanitizer::sanitizeForSerialization($request->input('context', [])),
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

        try {
            // Send via queue by default, or synchronously if queue is disabled
            if (config('sorane.javascript_errors.queue', true)) {
                SendJavaScriptErrorToSoraneJob::dispatch($errorData);
            } else {
                SendJavaScriptErrorToSoraneJob::dispatchSync($errorData);
            }

            return response()->json([
                'success' => true,
                'message' => 'Error received',
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to process error',
            ], 500);
        }
    }

    protected function sanitizeBreadcrumbs(array $breadcrumbs): array
    {
        $maxBreadcrumbs = config('sorane.javascript_errors.max_breadcrumbs', 20);

        // Limit the number of breadcrumbs
        $breadcrumbs = array_slice($breadcrumbs, -$maxBreadcrumbs);

        // Sanitize each breadcrumb
        return array_map(function ($breadcrumb) {
            return [
                'timestamp' => $breadcrumb['timestamp'] ?? now()->format('c'),
                'category' => $breadcrumb['category'] ?? 'unknown',
                'message' => substr($breadcrumb['message'] ?? '', 0, 500),
                'data' => DataSanitizer::sanitizeForSerialization($breadcrumb['data'] ?? []),
            ];
        }, $breadcrumbs);
    }
}

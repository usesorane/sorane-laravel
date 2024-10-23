<?php

namespace Sorane\ErrorReporting;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Request;
use Throwable;

class Sorane
{
    public function report(Throwable $exception): void
    {
        $request = Request::instance();
        $user = Auth::user();

        // Get PHP version
        $phpVersion = phpversion();

        // Get Laravel version
        $laravelVersion = app()->version();

        // Get headers
        $headers = $request->headers->all();

        // Remove sensitive headers
        $sensitiveHeaders = ['cookie', 'authorization', 'x-csrf-token', 'x-xsrf-token'];

        $headers = array_map(function ($value, $header) use ($sensitiveHeaders) {
            return in_array($header, $sensitiveHeaders) ? '***' : $value;
        }, $headers, array_keys($headers));

        $headers = json_encode($headers);

        // Get code
        $file = $exception->getFile();
        $line = $exception->getLine();
        $context = null;
        $highlightLine = null; // Initialize highlight line

        // Get the contents of the file to send surrounding code context
        if (is_readable($file) && filesize($file) < 1024 * 1024) { // Limit to 1MB
            $lines = file($file);
            if (is_array($lines)) {
                $startLine = max(0, $line - 6); // 5 lines before the error line
                $contextLines = array_slice($lines, $startLine, 11, true); // Total 11 lines
                $context = implode('', $contextLines);

                // Calculate the highlight line (relative position within the context)
                $highlightLine = $line - $startLine; // This gives the position of the error line in the 11 lines slice
            }
        }

        $time = Carbon::now()->toDateTimeString();

        // Trace
        $trace = $exception->getTraceAsString();
        $maxTraceLength = 5000; // for example, limiting trace length

        if (strlen($trace) > $maxTraceLength) {
            $trace = substr($trace, 0, $maxTraceLength).'... (truncated)';
        }

        $data = [
            'for' => 'sorane',
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $line,
            'context' => $context,
            'highlight_line' => $highlightLine,
            'trace' => $trace,
            'type' => get_class($exception),
            'time' => $time,
            'environment' => config('app.env'),
            'user' => $user?->only('id', 'email'),
            'php_version' => $phpVersion,
            'laravel_version' => $laravelVersion,
        ];

        if ($request = Request::instance()) {
            $data['url'] = $request->fullUrl();
            $data['method'] = $request->method();
            $data['headers'] = $headers;
        } else {
            $data['url'] = null;
            $data['method'] = null;
            $data['headers'] = null;
        }

        try {
            Http::withToken(config('sorane.key'))
                ->withHeaders(['User-Agent' => 'Sorane-Error-Reporter/1.0'])
                ->timeout(5)
                ->post('https://api.sorane.io/v1/report', $data);
        } catch (\Throwable $e) {
        }
    }
}

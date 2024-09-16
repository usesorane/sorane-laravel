<?php

namespace Sorane\ErrorReporting;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Carbon;

class Sorane
{
    public function report(\Throwable $exception): void
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

        foreach ($sensitiveHeaders as $header) {
            if (array_key_exists($header, $headers)) {
                $headers[$header] = '***';
            }
        }

        $headers = json_encode($headers);

        // Get code
        $file = $exception->getFile();
        $line = $exception->getLine();
        $context = null;

        // Get the contents of the file to send surrounding code context
        if (is_readable($file)) {
            $lines = file($file);
            if (is_array($lines)) {
                $startLine = max(0, $line - 6); // 5 lines before
                $contextLines = array_slice($lines, $startLine, 11, true); // Total 11 lines
                $context = implode('', $contextLines);
            }
        }

        $time = Carbon::now()->toDateTimeString();

        $data = [
            'for' => 'sorane',
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $line,
            'context' => $context,
            'trace' => $exception->getTraceAsString(),
            'type' => get_class($exception),
            'time' => $time,
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'headers' => $headers,
            'environment' => config('app.env'),
            'user' => $user?->only('id', 'email'),
            'php_version' => $phpVersion,
            'laravel_version' => $laravelVersion,
        ];

        try {
            Http::withToken(config('sorane.key'))
                ->post('https://api.sorane.io/v1/report', $data);
        } catch (\Throwable $e) {
        }
    }
}

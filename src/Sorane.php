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

                // Clean up the code context (remove extra spaces/indents)
                $context = $this->cleanCode(implode('', $contextLines));

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
            'type' => get_class($exception),
            'environment' => config('app.env'),
            'trace' => $trace,
            'headers' => null,
            'context' => $context,
            'highlight_line' => $highlightLine,
            'user' => $user?->only('id', 'email'),
            'time' => $time,
            'url' => null,
            'method' => null,
            'php_version' => $phpVersion,
            'laravel_version' => $laravelVersion,
        ];

        if ($request = Request::instance()) {
            $data['url'] = $request->fullUrl();
            $data['method'] = $request->method();
            $data['headers'] = $headers;
        }

        try {
            Http::withToken(config('sorane.key'))
                ->withHeaders(['User-Agent' => 'Sorane-Error-Reporter/1.0'])
                ->timeout(5)
                ->post('https://api.sorane.io/v1/report', $data);
        } catch (\Throwable $e) {
        }
    }

    private function cleanCode(string $code): string
    {
        // Split the code into individual lines
        $lines = explode("\n", $code);

        // Trim each line to remove leading/trailing whitespace
        $trimmedLines = array_map('rtrim', $lines);

        // Find the first line with actual content and determine the minimum indentation
        $minIndent = null;
        foreach ($trimmedLines as $line) {
            if (trim($line) !== '') { // Skip empty lines
                $indent = strlen($line) - strlen(ltrim($line));
                if ($minIndent === null || $indent < $minIndent) {
                    $minIndent = $indent;
                }
            }
        }

        // Remove the minimum indentation from all lines
        if ($minIndent > 0) {
            foreach ($trimmedLines as &$line) {
                if (trim($line) !== '') {
                    $line = substr($line, $minIndent);
                }
            }
        }

        // Rejoin the lines and return the cleaned-up code
        return implode("\n", $trimmedLines);
    }
}

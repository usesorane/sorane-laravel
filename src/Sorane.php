<?php

declare(strict_types=1);

namespace Sorane\Laravel;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Sorane\Laravel\Analytics\FingerprintGenerator;
use Sorane\Laravel\Events\EventTracker;
use Sorane\Laravel\Jobs\SendEventToSoraneJob;
use Sorane\Laravel\Services\SoraneApiClient;
use Sorane\Laravel\Utilities\DataSanitizer;
use Throwable;

class Sorane
{
    public function report(Throwable $exception): void
    {
        if (! config('sorane.error_reporting.enabled', true)) {
            return;
        }

        $request = Request::instance();
        $user = Auth::user();

        // Get PHP version
        $phpVersion = phpversion();

        // Get Laravel version
        $laravelVersion = app()->version();

        // Initialize headers, URL, and method as null
        $headers = null;
        $url = null;
        $method = null;

        // Determine if the error occurred in a console command
        $isConsole = app()->runningInConsole();

        // If the error occurred via HTTP, gather request data
        if (! $isConsole) {
            // Get headers
            $headers = $request->headers->all();

            // Remove sensitive headers
            $sensitiveHeaders = ['cookie', 'authorization', 'x-csrf-token', 'x-xsrf-token'];

            foreach ($headers as $header => &$value) {
                if (in_array($header, $sensitiveHeaders)) {
                    $value = '***';  // Mask sensitive headers
                }
            }

            $headers = json_encode($headers);

            $url = $request->fullUrl();
            $method = $request->method();
        }

        // Get code context
        $file = $exception->getFile();
        $line = $exception->getLine();
        $context = null;
        $highlightLine = null;

        $maxFileSize = config('sorane.error_reporting.max_file_size', 1048576);
        if (is_readable($file) && filesize($file) < $maxFileSize) {
            $lines = file($file);
            if (is_array($lines)) {
                $startLine = max(0, $line - 6); // 5 lines before the error line
                $contextLines = array_slice($lines, $startLine, 11, true); // Total 11 lines
                $context = $this->cleanCode(implode('', $contextLines));
                $highlightLine = $line - $startLine; // Relative line to highlight
            }
        }

        // Gather console-specific data if applicable
        $consoleCommand = null;
        $consoleArguments = null;
        $consoleOptions = null;

        if ($isConsole) {
            // Get the command and its input if available
            if (defined('ARTISAN_BINARY')) {
                $consoleCommand = implode(' ', $_SERVER['argv'] ?? []);
            }

            // You can gather arguments/options if available (e.g., in custom exception handlers)
            $consoleArguments = json_encode(request()->server('argv') ?? []);
        }

        // Trace
        $trace = $exception->getTraceAsString();
        $maxTraceLength = config('sorane.error_reporting.max_trace_length', 5000);

        if (mb_strlen($trace) > $maxTraceLength) {
            $trace = mb_substr($trace, 0, $maxTraceLength).'... (truncated)';
        }

        $time = Carbon::now()->toDateTimeString();

        // Data array to send
        $data = [
            'for' => 'sorane',
            'message' => $exception->getMessage(),
            'file' => $file,
            'line' => $line,
            'type' => get_class($exception),
            'environment' => config('app.env'),
            'trace' => $trace,
            'headers' => $headers,
            'context' => $context,
            'highlight_line' => $highlightLine,
            'user' => $user ? ['id' => $user->id, 'email' => $user->email] : null,
            'time' => $time,
            'url' => $url,
            'method' => $method,
            'php_version' => $phpVersion,
            'laravel_version' => $laravelVersion,
            'is_console' => $isConsole,
            'console_command' => $consoleCommand,
            'console_arguments' => $consoleArguments,
            'console_options' => $consoleOptions,
        ];

        try {
            $client = app(SoraneApiClient::class);
            $client->sendErrorReport($data);
        } catch (Throwable $e) {
            // Log the failure but don't rethrow to avoid infinite loops
            Log::warning('Failed to send error report to Sorane: '.$e->getMessage());
        }
    }

    public function trackEvent(string $eventName, array $properties = [], ?int $userId = null, bool $validate = true): void
    {
        if (! config('sorane.events.enabled', true)) {
            return;
        }

        // Validate event name by default (can be disabled for flexibility)
        if ($validate) {
            EventTracker::ensureValidEventName($eventName);
        }

        $user = $userId ? ['id' => $userId] : (Auth::user() ? ['id' => Auth::id()] : null);

        $eventData = [
            'event_name' => $eventName,
            'properties' => DataSanitizer::sanitizeForSerialization($properties),
            'user' => $user,
            'timestamp' => Carbon::now()->toISOString(),
            'url' => request()->fullUrl(),
            'user_agent_hash' => FingerprintGenerator::generateUserAgentHash(),
            'session_id_hash' => FingerprintGenerator::generateSessionIdHash(),
        ];

        // Dispatch job to send event data
        if (config('sorane.events.queue', true)) {
            SendEventToSoraneJob::dispatch($eventData);
        } else {
            SendEventToSoraneJob::dispatchSync($eventData);
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
            if (mb_trim($line) !== '') { // Skip empty lines
                $indent = mb_strlen($line) - mb_strlen(mb_ltrim($line));
                if ($minIndent === null || $indent < $minIndent) {
                    $minIndent = $indent;
                }
            }
        }

        // Remove the minimum indentation from all lines
        if ($minIndent > 0) {
            foreach ($trimmedLines as &$line) {
                if (mb_trim($line) !== '') {
                    $line = mb_substr($line, $minIndent);
                }
            }
        }

        // Rejoin the lines and return the cleaned-up code
        return implode("\n", $trimmedLines);
    }
}

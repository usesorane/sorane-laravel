<?php

namespace Sorane\Sorane;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Http;
use Sorane\Sorane\Commands\SoraneTestCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class SoraneServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('sorane-laravel')
            ->hasConfigFile()
            ->hasCommand(SoraneTestCommand::class);
    }

    public function packageBooted(): void
    {
        $this->registerExceptionHandler();
    }

    protected function registerExceptionHandler(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);

        $handler->reportable(function (Throwable $e) {
            $this->sendToSorane($e);
        });
    }

    protected function sendToSorane(Throwable $exception): void
    {
        $request = request();
        $user = auth()->user();

        // Get PHP version
        $phpVersion = phpversion();

        // Get Laravel version
        $laravelVersion = app()->version();

        // Get headers
        $headers = $request->headers->all();
        // Remove sensitive headers
        $headers['cookie'] = '***';
        $headers['authorization'] = '***';
        $headers['x-csrf-token'] = '***';
        $headers['x-xsrf-token'] = '***';
        $headers = json_encode($headers);

        // Get code
        $line = $exception->getLine();
        $file = $exception->getFile();

        // Get the contents of the file to send surrounding code context
        $lines = file($file);

        $context = array_slice($lines, max(0, $line - 5), 10, true); // Get 5 lines before and after the error
        $context = json_encode($context);

        $data = [
            'for' => 'sorane',
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $line,
            'context' => $context,
            'trace' => $exception->getTraceAsString(),
            'type' => get_class($exception),
            'time' => now()->toDateTimeString(),
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'headers' => $headers,
            'environment' => config('app.env'),
            'user' => $user?->only('id', 'email'),
            'php_version' => $phpVersion,
            'laravel_version' => $laravelVersion,
        ];

        try {
            Http::withToken(config('services.sorane.key'))
                ->post('https://api.sorane.io/v1/report', $data);
        } catch (\Exception $e) {
            // Do nothing
        }
    }
}

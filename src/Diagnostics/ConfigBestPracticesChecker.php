<?php

namespace Sorane\ErrorReporting\Diagnostics;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

class ConfigBestPracticesChecker
{
    public function __construct(private ConfigRepository $config) {}

    /**
     * Run all checks and return a list of CheckResult objects.
     *
     * @return array<int, CheckResult>
     */
    public function run(): array
    {
        $results = [];

        // Example checks as requested
        $results[] = $this->checkAppDebugFalse();
        $results[] = $this->checkSessionSecureTrue();

        // Additional sensible defaults
        $results[] = $this->checkAppEnvNotProductionWithDebug();
        $results[] = $this->checkAppUrlHttps();
        $results[] = $this->checkLogLevelNotDebugInProduction();
        $results[] = $this->checkQueueDefaultConnection();

        return $results;
    }

    private function checkAppDebugFalse(): CheckResult
    {
        $current = (bool) $this->config->get('app.debug');

        return new CheckResult(
            id: 'app.debug_false',
            description: 'app.debug should be false in production',
            passed: $current === false,
            current: $current,
            expected: false,
            severity: 'critical',
            recommendation: 'Set APP_DEBUG=false in your .env for production.',
            helpUrl: 'https://laravel.com/docs/configuration#debug-mode'
        );
    }

    private function checkSessionSecureTrue(): CheckResult
    {
        $current = (bool) $this->config->get('session.secure', false);

        return new CheckResult(
            id: 'session.secure_true',
            description: 'session.secure should be true to transmit cookies only over HTTPS',
            passed: $current === true,
            current: $current,
            expected: true,
            severity: 'high',
            recommendation: 'Set SESSION_SECURE_COOKIE=true and ensure your app is served over HTTPS.',
            helpUrl: 'https://laravel.com/docs/session#configuring-the-session'
        );
    }

    private function checkAppEnvNotProductionWithDebug(): CheckResult
    {
        $env = (string) $this->config->get('app.env');
        $debug = (bool) $this->config->get('app.debug');
        $passed = ! ($env === 'production' && $debug === true);

        return new CheckResult(
            id: 'app.debug_in_production',
            description: 'Debug should never be enabled in production environment',
            passed: $passed,
            current: ['env' => $env, 'debug' => $debug],
            expected: ['env' => 'production', 'debug' => false],
            severity: 'critical',
            recommendation: 'Ensure APP_ENV=production and APP_DEBUG=false in production.',
            helpUrl: 'https://laravel.com/docs/configuration#environment-configuration'
        );
    }

    private function checkAppUrlHttps(): CheckResult
    {
        $url = (string) $this->config->get('app.url', '');
        $passed = str_starts_with(strtolower($url), 'https://');

        return new CheckResult(
            id: 'app.url_https',
            description: 'App URL should use HTTPS',
            passed: $passed,
            current: $url,
            expected: 'https://…',
            severity: 'medium',
            recommendation: 'Set APP_URL to an https URL and configure trusted proxies if behind a load balancer.',
            helpUrl: 'https://laravel.com/docs/requests#configuring-trusted-proxies'
        );
    }

    private function checkLogLevelNotDebugInProduction(): CheckResult
    {
        $env = (string) $this->config->get('app.env');
        $level = (string) $this->config->get('logging.channels.stack.level', $this->config->get('logging.default'));
        // If in production, level should not be 'debug'
        $passed = ! ($env === 'production' && strtolower((string) $level) === 'debug');

        return new CheckResult(
            id: 'logging.level_production',
            description: 'Logging level should not be debug in production',
            passed: $passed,
            current: ['env' => $env, 'level' => $level],
            expected: ['env' => 'production', 'level' => 'info or higher'],
            severity: 'medium',
            recommendation: 'Set LOG_LEVEL=info (or warning/error) in production.',
            helpUrl: 'https://laravel.com/docs/logging#log-levels'
        );
    }

    private function checkQueueDefaultConnection(): CheckResult
    {
        $connection = (string) $this->config->get('queue.default', 'sync');
        $env = (string) $this->config->get('app.env');
        // In production, avoid sync queue
        $passed = ! ($env === 'production' && $connection === 'sync');

        return new CheckResult(
            id: 'queue.connection_production',
            description: 'Queue connection should not be sync in production',
            passed: $passed,
            current: ['env' => $env, 'connection' => $connection],
            expected: ['env' => 'production', 'connection' => 'database/redis/sqs/etc.'],
            severity: 'medium',
            recommendation: 'Configure QUEUE_CONNECTION to a persistent driver in production (database, redis, sqs, etc.).',
            helpUrl: 'https://laravel.com/docs/queues#driver-prerequisites'
        );
    }
}

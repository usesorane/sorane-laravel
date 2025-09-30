<?php

namespace Sorane\ErrorReporting\Guard;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Sorane\ErrorReporting\Diagnostics\CheckResult;

class OpsChecks
{
    public function __construct(private ConfigRepository $config) {}

    /**
     * @return array<int, CheckResult>
     */
    public function run(): array
    {
        return [
            $this->checkMailDriverSends(),
            $this->checkCacheDriverInProduction(),
            $this->checkOpcacheEnabled(),
            $this->checkQueueWorkerRecommendation(),
        ];
    }

    private function checkMailDriverSends(): CheckResult
    {
        $env = (string) $this->config->get('app.env');
        $default = (string) ($this->config->get('mail.default', $this->config->get('mail.mailer', 'smtp')));

        $nonSending = ['log', 'array'];
        $isNonSending = in_array(strtolower($default), $nonSending, true);

        // Handle wrapper mailers: failover/roundrobin — only warn if all underlying are non-sending
        if (in_array(strtolower($default), ['failover', 'roundrobin'], true)) {
            $wrapperKey = 'mail.mailers.'.strtolower($default).'.mailers';
            $children = $this->config->get($wrapperKey, []);
            if (is_array($children) && count($children) > 0) {
                $allNonSending = true;
                foreach ($children as $child) {
                    $t = strtolower((string) $child);
                    if (! in_array($t, $nonSending, true)) {
                        $allNonSending = false;
                        break;
                    }
                }
                $isNonSending = $allNonSending;
            }
        }

        $passed = ! ($env === 'production' && $isNonSending);

        return new CheckResult(
            id: 'mail.driver_sends',
            description: 'Mail driver should send emails in production (avoid log/array)',
            passed: $passed,
            current: ['env' => $env, 'default' => $default],
            expected: ['env' => 'production', 'default' => 'smtp/sendmail/ses/mailgun/postmark/etc.'],
            severity: 'medium',
            recommendation: 'Set MAIL_MAILER to a real transport (smtp, ses, mailgun, postmark, etc.) in production.',
            helpUrl: 'https://laravel.com/docs/mail#configuration'
        );
    }

    private function checkCacheDriverInProduction(): CheckResult
    {
        $env = (string) $this->config->get('app.env');
        $driver = (string) $this->config->get('cache.default', 'file');
        $passed = ! ($env === 'production' && in_array($driver, ['array', 'file'], true));

        return new CheckResult(
            id: 'cache.driver_production',
            description: 'Cache driver should not be array/file in production',
            passed: $passed,
            current: ['env' => $env, 'driver' => $driver],
            expected: ['env' => 'production', 'driver' => 'redis/memcached/database'],
            severity: 'medium',
            recommendation: 'Set CACHE_STORE=redis (or memcached/database) in production for better performance.',
            helpUrl: 'https://laravel.com/docs/cache#configuration'
        );
    }

    private function checkOpcacheEnabled(): CheckResult
    {
        // We can only hint; actual opcache status is a PHP runtime concern
        $ini = [
            'opcache.enable_cli' => (string) ini_get('opcache.enable_cli'),
            'opcache.jit' => (string) ini_get('opcache.jit'),
        ];
        $enabled = function_exists('opcache_get_status') || (ini_get('opcache.enable') ?: '0') === '1';

        return new CheckResult(
            id: 'php.opcache_enabled',
            description: 'PHP OPcache should be enabled in production for performance',
            passed: (bool) $enabled,
            current: $ini,
            expected: ['opcache.enable' => '1'],
            severity: 'low',
            recommendation: 'Enable OPcache in php.ini on production for significant performance gains.',
            helpUrl: 'https://www.php.net/manual/en/opcache.installation.php'
        );
    }

    private function checkQueueWorkerRecommendation(): CheckResult
    {
        $env = (string) $this->config->get('app.env');
        $queueConn = (string) $this->config->get('queue.default', 'sync');
        $recommendHorizon = $env === 'production' && in_array($queueConn, ['redis'], true);

        // This is a recommendation (pass even if not using Horizon)
        return new CheckResult(
            id: 'queue.horizon_recommended',
            description: 'Use Laravel Horizon for robust queue management when using Redis in production',
            passed: true,
            current: ['env' => $env, 'connection' => $queueConn],
            expected: 'Horizon recommended with Redis in production',
            severity: $recommendHorizon ? 'low' : 'low',
            recommendation: $recommendHorizon ? 'Consider installing Laravel Horizon for queues in production.' : null,
            helpUrl: 'https://laravel.com/docs/horizon'
        );
    }
}

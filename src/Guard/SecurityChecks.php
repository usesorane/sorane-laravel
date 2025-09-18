<?php

namespace Sorane\ErrorReporting\Guard;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Sorane\ErrorReporting\Diagnostics\CheckResult;

class SecurityChecks
{
    public function __construct(private ConfigRepository $config) {}

    /**
     * @return array<int, CheckResult>
     */
    public function run(): array
    {
        return [
            $this->checkSessionSameSite(),
            $this->checkAppCipherStrength(),
            $this->checkAppKeyEntropy(),
            $this->checkTrustedProxiesConfigured(),
        ];
    }

    private function checkSessionSameSite(): CheckResult
    {
        $sameSite = $this->config->get('session.same_site');
        $secure = (bool) $this->config->get('session.secure', false);
        $env = (string) $this->config->get('app.env');

        $passed = true;
        $severity = 'low';
        $description = 'session.same_site should be lax or strict (avoid none unless secure)';
        $recommendation = 'Set SESSION_SAME_SITE=lax (or strict). If using none for cross-site cookies, ensure SESSION_SECURE_COOKIE=true and HTTPS.';
        if ($env === 'production') {
            if ($sameSite === null || $sameSite === '') {
                $passed = false;
                $severity = 'medium';
            } elseif (strtolower((string) $sameSite) === 'none' && $secure !== true) {
                $passed = false;
                $severity = 'high';
                $description = 'session.same_site=none requires secure cookies over HTTPS';
                $recommendation = 'Set SESSION_SECURE_COOKIE=true and serve over HTTPS when using SAME_SITE=none.';
            } elseif (! in_array(strtolower((string) $sameSite), ['lax', 'strict', 'none'], true)) {
                $passed = false;
                $severity = 'low';
                $recommendation = 'Use lax (recommended) or strict for production.';
            }
        }

        return new CheckResult(
            id: 'session.same_site',
            description: $description,
            passed: $passed,
            current: $sameSite,
            expected: 'lax or strict (or none with secure=true)',
            severity: $severity,
            recommendation: $recommendation,
            helpUrl: 'https://laravel.com/docs/session#same-site-cookies'
        );
    }

    private function checkAppCipherStrength(): CheckResult
    {
        $cipher = (string) $this->config->get('app.cipher', 'AES-256-CBC');
        $allowed = ['aes-256-cbc', 'aes-256-gcm'];
        $passed = in_array(strtolower($cipher), $allowed, true);

        return new CheckResult(
            id: 'app.cipher_strength',
            description: 'App cipher should be AES-256-CBC or AES-256-GCM',
            passed: $passed,
            current: $cipher,
            expected: 'AES-256-CBC or AES-256-GCM',
            severity: $passed ? 'low' : 'high',
            recommendation: 'Use AES-256-CBC (Laravel default) unless you specifically need GCM.',
            helpUrl: 'https://laravel.com/docs/encryption'
        );
    }

    private function checkAppKeyEntropy(): CheckResult
    {
        $key = (string) $this->config->get('app.key', '');
        $env = (string) $this->config->get('app.env');
        $bytes = null;
        $passed = false;

        if ($key === '') {
            $passed = false;
        } elseif (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            $bytes = $decoded !== false ? strlen($decoded) : null;
            $passed = $decoded !== false && strlen($decoded) === 32;
        } else {
            // Non-base64 key - best effort: require at least 32 characters
            $bytes = strlen($key);
            $passed = strlen($key) >= 32;
        }

        return new CheckResult(
            id: 'app.key_entropy',
            description: 'APP_KEY should be a 32-byte random key (base64 encoded)',
            passed: $passed,
            current: ['set' => $key !== '', 'length_bytes' => $bytes],
            expected: 'base64-encoded 32 bytes',
            severity: $env === 'production' ? 'critical' : 'high',
            recommendation: 'Run php artisan key:generate and set APP_KEY in .env.',
            helpUrl: 'https://laravel.com/docs/encryption#configuration'
        );
    }

    private function checkTrustedProxiesConfigured(): CheckResult
    {
        $url = (string) $this->config->get('app.url', '');
        $hasTrustedProxyConfig = $this->config->has('trustedproxy.proxies') || $this->config->has('trustedproxy.headers') || getenv('TRUSTED_PROXIES');
        $httpsUrl = str_starts_with(strtolower($url), 'https://');

        // Only warn when app is intended to be served via HTTPS and no trusted proxy config exists
        $passed = $hasTrustedProxyConfig || ! $httpsUrl;

        return new CheckResult(
            id: 'http.trusted_proxies',
            description: 'Configure trusted proxies when running behind load balancers/CDNs',
            passed: $passed,
            current: ['app_url' => $url, 'trustedproxy_configured' => (bool) $hasTrustedProxyConfig],
            expected: 'Trusted proxies configured when using HTTPS behind a proxy',
            severity: 'low',
            recommendation: 'Set TRUSTED_PROXIES or configure fideloper/proxy (or Laravel TrustedProxy) to avoid mixed scheme issues.',
            helpUrl: 'https://laravel.com/docs/requests#configuring-trusted-proxies'
        );
    }
}

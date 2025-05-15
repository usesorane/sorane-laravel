<?php

namespace Sorane\ErrorReporting\Commands;

use Illuminate\Console\Command;

class SoraneTestCommand extends Command
{
    public $signature = 'sorane:test';

    public $description = 'This command will send a test message to Sorane';

    public function handle(): int
    {
        // 1. Test if the config file is up to date
        $this->info('Testing Sorane configuration...');

        $configFile = config('sorane');

        // Test the structure of the config file
        if (! is_array($configFile)) {
            $this->error('Sorane configuration file is not valid. Please check your configuration.');

            return self::FAILURE;
        }

        // Check if all required config entries exist
        $requiredKeys = ['key', 'website_analytics'];
        foreach ($requiredKeys as $key) {
            if (! array_key_exists($key, $configFile)) {
                $this->error("Required config key '{$key}' is missing. Please check your configuration.");

                return self::FAILURE;
            }
        }

        // Check website_analytics subkeys
        if (! is_array($configFile['website_analytics'])) {
            $this->error("Config key 'website_analytics' must be an array. Please check your configuration.");

            return self::FAILURE;
        }

        $analyticsRequiredKeys = ['enabled', 'queue'];
        foreach ($analyticsRequiredKeys as $key) {
            if (! array_key_exists($key, $configFile['website_analytics'])) {
                $this->error("Required config key 'website_analytics.{$key}' is missing. Please check your configuration.");

                return self::FAILURE;
            }
        }

        if (empty($configFile['key'])) {
            $this->error('Sorane API key is not set. Please check your configuration.');

            return self::FAILURE;
        }

        // Check if website_analytics.enabled is a boolean
        if (! is_bool($configFile['website_analytics']['enabled'])) {
            $this->error("Config key 'website_analytics.enabled' must be a boolean. Please check your configuration.");

            return self::FAILURE;
        }

        // Check if website_analytics.queue is not empty if analytics are enabled
        if ($configFile['website_analytics']['enabled'] && empty($configFile['website_analytics']['queue'])) {
            $this->error("Config key 'website_analytics.queue' cannot be empty when analytics are enabled.");

            return self::FAILURE;
        }

        // Check environment variables
        if (empty(env('SORANE_KEY'))) {
            $this->warn('Environment variable SORANE_KEY is not set. Using fallback value.');
        }

        // Validate the configuration
        $this->info('Sorane configuration is valid.');
        $this->info('API Key: '.substr($configFile['key'], 0, 4).'******');
        $this->info('Website Analytics: '.($configFile['website_analytics']['enabled'] ? 'Enabled' : 'Disabled'));
        if ($configFile['website_analytics']['enabled']) {
            $this->info('Website Analytics Queue: '.$configFile['website_analytics']['queue']);
        }

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Sorane\Laravel\Logging;

use Monolog\Logger;
use Psr\Log\LoggerInterface;

class SoraneLogDriver
{
    /**
     * Create a custom Monolog instance for Sorane
     */
    public function __invoke(array $config): LoggerInterface
    {
        $logger = new Logger($config['channel'] ?? 'sorane');

        // Add the Sorane handler
        $logger->pushHandler(new SoraneLogHandler(
            $config['level'] ?? 'debug',
            $config['bubble'] ?? true
        ));

        return $logger;
    }
}

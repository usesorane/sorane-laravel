<?php

namespace Sorane\ErrorReporting\Logging;

use Monolog\Logger;
use Psr\Log\LoggerInterface;

class SoraneLogDriver
{
    /**
     * Create a custom Monolog instance for Sorane
     */
    public function __invoke(array $config): LoggerInterface
    {
        $logger = new Logger('sorane');

        // Add the Sorane handler
        $logger->pushHandler(new SoraneLogHandler(
            $config['level'] ?? 'debug',
            $config['bubble'] ?? true
        ));

        return $logger;
    }
}

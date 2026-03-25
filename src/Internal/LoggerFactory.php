<?php

	declare(strict_types=1);

	namespace Sharkord\Internal;

	use Monolog\ErrorHandler;
	use Monolog\Formatter\LineFormatter;
	use Monolog\Handler\StreamHandler;
	use Monolog\Level;
	use Monolog\Logger;
	use Psr\Log\LoggerInterface;

	/**
	 * Class LoggerFactory
	 *
	 * Creates the default PSR-3 Monolog logger instance for the framework.
	 *
	 * @package Sharkord\Internal
	 */
	class LoggerFactory {

		/**
		 * Creates and returns a configured Monolog logger.
		 *
		 * @param string $logLevel The minimum log level name (e.g. 'Notice', 'Debug').
		 * @return LoggerInterface
		 * @throws \InvalidArgumentException If the log level name is invalid.
		 *
		 * @example
		 * ```php
		 * $logger = \Sharkord\Internal\LoggerFactory::create('Debug');
		 * $logger->info("Logger initialised.");
		 * ```
		 */
		public static function create(string $logLevel): LoggerInterface {

			try {
				$level = Level::fromName(ucfirst(strtolower($logLevel)));
			} catch (\ValueError $e) {
				throw new \InvalidArgumentException(
					"Invalid log level '{$logLevel}': " . $e->getMessage(), 0, $e
				);
			}

			$formatter    = new LineFormatter(null, 'd/m h:i:sA', false, true);
			$streamHandler = new StreamHandler('php://stdout', $level);
			$streamHandler->setFormatter($formatter);

			$logger = new Logger('sharkord');
			$logger->pushHandler($streamHandler);

			ErrorHandler::register($logger);

			return $logger;

		}
		
	}
	
?>
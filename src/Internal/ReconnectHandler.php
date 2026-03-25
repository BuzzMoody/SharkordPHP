<?php

	declare(strict_types=1);

	namespace Sharkord\Internal;

	use Psr\Log\LoggerInterface;
	use React\EventLoop\LoopInterface;

	/**
	 * Class ReconnectHandler
	 *
	 * Manages scheduled reconnection attempts using exponential backoff.
	 *
	 * @package Sharkord\Internal
	 */
	class ReconnectHandler {

		private int  $attempts    = 0;
		private bool $inProgress  = false;

		/**
		 * ReconnectHandler constructor.
		 *
		 * @param LoopInterface   $loop        The ReactPHP event loop.
		 * @param LoggerInterface $logger      The PSR-3 logger instance.
		 * @param int             $maxAttempts Maximum attempts before giving up.
		 * @param \Closure        $connectFn   The async connection callable. Must return a PromiseInterface.
		 * @param \Closure        $onSuccess   Called with no arguments when a reconnect succeeds.
		 * @param \Closure        $onExhausted Called with no arguments when all attempts are exhausted.
		 */
		public function __construct(
			private readonly LoopInterface   $loop,
			private readonly LoggerInterface $logger,
			private readonly int             $maxAttempts,
			private readonly \Closure        $connectFn,
			private readonly \Closure        $onSuccess,
			private readonly \Closure        $onExhausted,
		) {}

		/**
		 * Resets the attempt counter after a successful connection.
		 *
		 * @return void
		 *
		 * @example
		 * ```php
		 * // Called automatically after a successful connection.
		 * $reconnectHandler->reset();
		 * ```
		 */
		public function reset(): void {

			$this->attempts   = 0;
			$this->inProgress = false;

		}

		/**
		 * Schedules the next reconnection attempt using exponential backoff.
		 *
		 * Backs off at 2^attempt seconds (2s, 4s, 8s...) capped at 60 seconds.
		 *
		 * @return void
		 *
		 * @example
		 * ```php
		 * // Called automatically when the gateway connection is lost.
		 * $reconnectHandler->attempt();
		 * ```
		 */
		public function attempt(): void {

			if ($this->inProgress) {
				$this->logger->debug("Reconnect already in progress, ignoring duplicate trigger.");
				return;
			}

			$this->inProgress = true;
			$this->attempts++;

			if ($this->attempts > $this->maxAttempts) {
				$this->logger->error(
					"Reconnection failed after {$this->maxAttempts} attempts. Exiting."
				);
				$this->inProgress = false;
				($this->onExhausted)();
				return;
			}

			$delay = min(2 ** $this->attempts, 60);

			$this->logger->notice(
				"Reconnect attempt {$this->attempts}/{$this->maxAttempts} in {$delay}s..."
			);

			$this->loop->addTimer($delay, function () {

				($this->connectFn)()
					->then(function () {
						$this->reset();
						($this->onSuccess)();
					})
					->catch(function (mixed $reason) {
						$this->logger->error("Reconnect attempt failed: " . PromiseUtils::reasonToString($reason));
						$this->inProgress = false;
						$this->attempt();
					});

			});

		}

	}
	
?>
<?php

	declare(strict_types=1);

	namespace Sharkord\Internal;

	/**
	 * Class PromiseUtils
	 *
	 * Utility helpers for working with ReactPHP Promises.
	 *
	 * @package Sharkord\Internal
	 */
	class PromiseUtils {

		/**
		 * Converts any Promise rejection reason to a human-readable string.
		 *
		 * Promise rejections may be Throwable instances, plain strings, or arbitrary
		 * values. This method normalises all cases for safe logging, guaranteeing a
		 * string return value even when JSON encoding fails.
		 *
		 * @param mixed $reason The rejection reason.
		 * @return string A human-readable representation of the rejection reason.
		 *
		 * @example
		 * ```php
		 * $promise->catch(function(mixed $reason) use ($sharkord) {
		 *     $sharkord->logger->error(
		 *         \Sharkord\Internal\PromiseUtils::reasonToString($reason)
		 *     );
		 * });
		 * ```
		 */
		public static function reasonToString(mixed $reason): string {

			return match (true) {
				$reason instanceof \Throwable => $reason->getMessage(),
				is_string($reason)            => $reason,
				default                       => json_encode($reason) ?: print_r($reason, true),
			};

		}

	}
	
?>
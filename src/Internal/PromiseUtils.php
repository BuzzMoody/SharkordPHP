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

		/**
		 * Validates that an RPC response has `type === 'data'`, indicating success.
		 *
		 * Centralises the repeated response-validation pattern used by every
		 * mutation call across the framework. Throws on failure so the caller can
		 * rely on a truthy return for the success path.
		 *
		 * @param array  $response The decoded RPC response array.
		 * @param string $action   A short human-readable description of the action
		 *                         (e.g. "edit message") used in the exception message.
		 * @return true Always returns true on success.
		 *
		 * @throws \RuntimeException If the response does not indicate success.
		 *
		 * @example
		 * ```php
		 * $this->sharkord->gateway->sendRpc("mutation", [
		 *     "input" => ["messageId" => $this->id],
		 *     "path"  => "messages.delete",
		 * ])->then(fn(array $r) => PromiseUtils::expectDataResponse($r, 'delete message'));
		 * ```
		 */
		public static function expectDataResponse(array $response, string $action): true {

			if (isset($response['type']) && $response['type'] === 'data') {
				return true;
			}

			throw new \RuntimeException(
				"Failed to {$action}. Server responded with: " . json_encode($response)
			);

		}

	}
	
?>
<?php

	declare(strict_types=1);

	namespace Sharkord\Models;

	use Sharkord\Sharkord;
	use Sharkord\Internal\PromiseUtils;
	use React\Promise\PromiseInterface;

	/**
	 * Class DirectMessage
	 *
	 * Represents a direct message thread between the bot and another user,
	 * as returned by the dms.get API endpoint.
	 *
	 * @package Sharkord\Models
	 *
	 * @example
	 * ```php
	 * $sharkord->dms->get()->then(function(array $dms) {
	 *     foreach ($dms as $dm) {
	 *         echo "{$dm->user->name}: {$dm->unreadCount} unread\n";
	 *
	 *         if ($dm->unreadCount > 0) {
	 *             $dm->markRead();
	 *         }
	 *     }
	 * });
	 * ```
	 */
	class DirectMessage {

		/**
		 * DirectMessage constructor.
		 *
		 * @param Sharkord $sharkord     Reference to the main bot instance.
		 * @param int      $channelId    The channel ID for this DM thread.
		 * @param int      $userId       The ID of the other user in the DM thread.
		 * @param int      $unreadCount  The number of unread messages in this thread.
		 * @param int      $lastMessageAt Unix timestamp (ms) of the last message.
		 */
		public function __construct(
			private readonly Sharkord $sharkord,
			public readonly int $channelId,
			public readonly int $userId,
			public readonly int $unreadCount,
			public readonly int $lastMessageAt,
		) {}

		/**
		 * Factory method to create a DirectMessage from a raw API data array.
		 *
		 * @param array    $raw      The raw DM data array from the API.
		 * @param Sharkord $sharkord Reference to the main bot instance.
		 * @return self
		 */
		public static function fromArray(array $raw, Sharkord $sharkord): self {

			return new self(
				sharkord:      $sharkord,
				channelId:     (int) $raw['channelId'],
				userId:        (int) $raw['userId'],
				unreadCount:   (int) ($raw['unreadCount'] ?? 0),
				lastMessageAt: (int) ($raw['lastMessageAt'] ?? 0),
			);

		}

		/**
		 * Sends a text message into this DM thread.
		 *
		 * @param string $text The message content.
		 * @return PromiseInterface Resolves on success, rejects on failure.
		 *
		 * @example
		 * ```php
		 * $sharkord->dms->get()->then(function(array $dms) {
		 *     $dms[0]->send("Hello!");
		 * });
		 * ```
		 */
		public function send(string $text): PromiseInterface {

			$channel = $this->sharkord->channels->get($this->channelId);

			if ($channel) {
				return $channel->sendMessage($text);
			}

			return $this->sharkord->gateway->sendRpc("mutation", [
				"input" => [
					"content"   => "<p>" . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . "</p>",
					"channelId" => $this->channelId,
					"files"     => [],
				],
				"path" => "messages.send",
			])->then(fn(array $r) => PromiseUtils::expectDataResponse($r, 'send DM'));

		}

		/**
		 * Marks all messages in this DM thread as read.
		 *
		 * @return PromiseInterface Resolves on success, rejects on failure.
		 *
		 * @example
		 * ```php
		 * $sharkord->dms->get()->then(function(array $dms) {
		 *     foreach ($dms as $dm) {
		 *         if ($dm->unreadCount > 0) {
		 *             $dm->markRead();
		 *         }
		 *     }
		 * });
		 * ```
		 */
		public function markRead(): PromiseInterface {

			$channel = $this->sharkord->channels->get($this->channelId);

			if ($channel) {
				return $channel->markAsRead();
			}

			return $this->sharkord->gateway->sendRpc("mutation", [
				"input" => ["channelId" => $this->channelId],
				"path"  => "channels.markAsRead",
			])->then(fn(array $r) => PromiseUtils::expectDataResponse($r, 'mark DM as read'));

		}

		/**
		 * Returns the resolved User object for the other participant in this DM.
		 *
		 * @return User|null The User model, or null if not found in cache.
		 */
		public function getUser(): ?User {

			return $this->sharkord->users->get($this->userId);

		}

		/**
		 * Returns the resolved Channel object for this DM thread.
		 *
		 * @return Channel|null The Channel model, or null if not found in cache.
		 */
		public function getChannel(): ?Channel {

			return $this->sharkord->channels->get($this->channelId);

		}

		/**
		 * Returns the DirectMessage data as a plain array. Useful for debugging.
		 *
		 * @return array
		 */
		public function toArray(): array {

			return [
				'channelId'     => $this->channelId,
				'userId'        => $this->userId,
				'unreadCount'   => $this->unreadCount,
				'lastMessageAt' => $this->lastMessageAt,
			];

		}

		/**
		 * Magic getter. Provides shorthand property-style access to resolved models.
		 *
		 * Supported virtual properties:
		 * - $dm->user    Returns the resolved User object via UserManager.
		 * - $dm->channel Returns the resolved Channel object via ChannelManager.
		 *
		 * @param string $name Property name.
		 * @return mixed
		 */
		public function __get(string $name): mixed {

			return match($name) {
				'user'    => $this->getUser(),
				'channel' => $this->getChannel(),
				default   => null,
			};

		}

		/**
		 * Magic isset check.
		 *
		 * @param string $name Property name.
		 * @return bool
		 */
		public function __isset(string $name): bool {

			return match($name) {
				'user'    => $this->sharkord->users->get($this->userId) !== null,
				'channel' => $this->sharkord->channels->get($this->channelId) !== null,
				default   => false,
			};

		}

	}

?>
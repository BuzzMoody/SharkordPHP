<?php

	declare(strict_types=1);

	namespace Sharkord\Models;
	
	use Sharkord\Sharkord;
	use Sharkord\Permission;
	use Sharkord\Internal\GuardedAsync;
	use Sharkord\Internal\PromiseUtils;
	use Sharkord\Collections\Reactions;
	use Sharkord\Builders\MessageBuilder;

	use React\Promise\PromiseInterface;
	use React\Promise\Promise;
	use function React\Promise\reject;
	
	use LitEmoji\LitEmoji;
	
	/**
	 * Class Message
	 *
	 * Represents a received chat message.
	 *
	 * @package Sharkord\Models
	 */
	class Message {

		use GuardedAsync;
		
		/**
		 * @var array Stores all dynamic message data from the API
		 */
		private array $attributes = [];

		/**
		 * Message constructor.
		 *
		 * @param Sharkord $sharkord Reference to the main bot instance.
		 * @param array    $rawData  The raw array of data from the API.
		 */
		public function __construct(
			private Sharkord $sharkord,
			array $rawData
		) {
			$this->updateFromArray($rawData);
		}
		
		/**
		 * Factory method to create a Message from raw API data.
		 *
		 * @param array    $raw      The raw message data from the server.
		 * @param Sharkord $sharkord Reference to the main bot instance.
		 * @return self
		 */
		public static function fromArray(array $raw, Sharkord $sharkord): self {
			return new self($sharkord, $raw);
		}
		
		/**
		 * Updates the Message's information dynamically.
		 *
		 * @internal This method is for internal framework use only. Do not call this directly.
		 * @param array $raw The raw Message data.
		 * @return void
		 */
		public function updateFromArray(array $raw): void {
			
			// Extract mention data from the raw HTML before tags are stripped
			if (isset($raw['content'])) {
				$raw['mentionedUserIds'] = $this->parseMentionedUserIds($raw['content']);
				$raw['content'] = strip_tags($raw['content']);
			}

			// Merge the new data into our attributes array
			$this->attributes = array_merge($this->attributes, $raw);
			
		}

		/**
		 * Replies to this message in the same channel.
		 *
		 * Builds a {@see MessageBuilder} with the message author set as the reply
		 * target, so the mention span is prepended automatically. Delegates to
		 * {@see Channel::sendMessage()} for dispatch.
		 *
		 * @param string $text The reply content.
		 * @return PromiseInterface Resolves when the message is sent.
		 *
		 * @example
		 * ```php
		 * $sharkord->on(\Sharkord\Events::MESSAGE_CREATE, function(\Sharkord\Models\Message $message) {
		 *     if ($message->content === '!ping') {
		 *         $message->reply('Pong!');
		 *     }
		 * });
		 * ```
		 */
		public function reply(string $text): PromiseInterface {

			if ($this->channel && $this->author) {

				$builder = MessageBuilder::create()
					->setReply($this->author)
					->setContent($text);

				return $this->channel->sendMessage($builder);

			}

			return reject(new \RuntimeException("Channel or author not found for this message."));

		}
		
		/**
		 * Adds or toggles an emoji reaction on a specific message.
		 *
		 * @param string  $emoji   The emoji character(s) to use for the reaction.
		 * @return PromiseInterface Resolves on success, rejects on failure.
		 *
		 * @throws \RuntimeException If the bot lacks REACT_TO_MESSAGES.
		 * @throws \InvalidArgumentException If the emoji is invalid.
		 *
		 * @example
		 * ```php
		 * $message->react('👍')->then(function() {
		 *     echo "Reacted!\n";
		 * });
		 * ```
		 */
		public function react(string $emoji): PromiseInterface {

			return $this->guardedAsync(function () use ($emoji) {

				$this->sharkord->guard->requirePermission(Permission::REACT_TO_MESSAGES);

				if (!$this->isEmoji($emoji)) {
					throw new \InvalidArgumentException("Invalid emoji provided: '{$emoji}'");
				}

				$emojiText = $this->emojiToText($emoji);

				return $this->sharkord->gateway->sendRpc("mutation", [
					"input" => ["messageId" => $this->id, "emoji" => $emojiText],
					"path"  => "messages.toggleReaction"
				])->then(fn(array $r) => PromiseUtils::expectDataResponse($r, 'react to message'));

			});

		}
		
		/**
		 * Edits the content of this message.
		 *
		 * @param string $newContent The new message text.
		 * @return PromiseInterface Resolves with true when the message is edited.
		 *
		 * @throws \RuntimeException If the bot lacks ownership or MANAGE_MESSAGES.
		 *
		 * @example
		 * ```php
		 * $message->edit('Updated content.')->then(function() {
		 *     echo "Message edited.\n";
		 * });
		 * ```
		 */
		public function edit(string $newContent): PromiseInterface {

			return $this->guardedAsync(function () use ($newContent) {

				$this->sharkord->guard->requireOwnershipOrPermission(
					$this->author?->id,
					Permission::MANAGE_MESSAGES
				);

				return $this->sharkord->gateway->sendRpc("mutation", [
					"input" => ["messageId" => $this->id, "content" => $newContent],
					"path"  => "messages.edit"
				])->then(fn(array $r) => PromiseUtils::expectDataResponse($r, 'edit message'));

			});

		}

		/**
		 * Deletes this message.
		 *
		 * @return PromiseInterface Resolves with true when the message is deleted.
		 *
		 * @throws \RuntimeException If the bot lacks ownership or MANAGE_MESSAGES.
		 *
		 * @example
		 * ```php
		 * $message->delete()->then(function() {
		 *     echo "Message deleted.\n";
		 * });
		 * ```
		 */
		public function delete(): PromiseInterface {

			return $this->guardedAsync(function () {

				$this->sharkord->guard->requireOwnershipOrPermission(
					$this->author?->id,
					Permission::MANAGE_MESSAGES
				);

				return $this->sharkord->gateway->sendRpc("mutation", [
					"input" => ["messageId" => $this->id],
					"path"  => "messages.delete"
				])->then(fn(array $r) => PromiseUtils::expectDataResponse($r, 'delete message'));

			});

		}

		/**
		 * Toggles the pinned state of this message.
		 *
		 * Sends the togglePin mutation and waits for the subsequent messages.onUpdate
		 * subscription event to confirm and return the new pinned state.
		 *
		 * @param int $timeout Seconds to wait for the onUpdate confirmation before rejecting.
		 * @return PromiseInterface Resolves with a bool indicating the new pinned state (true = pinned, false = unpinned).
		 *
		 * @throws \RuntimeException If the bot lacks MANAGE_MESSAGES or the confirmation times out.
		 *
		 * @example
		 * ```php
		 * $message->togglePin()->then(function(bool $pinned) {
		 *     echo $pinned ? "Message pinned.\n" : "Message unpinned.\n";
		 * });
		 * ```
		 */
		public function togglePin(int $timeout = 10): PromiseInterface {

			return $this->guardedAsync(function () use ($timeout) {

				$this->sharkord->guard->requirePermission(Permission::MANAGE_MESSAGES);

				return $this->sharkord->gateway->sendRpc("mutation", [
					"input" => ["messageId" => $this->id],
					"path"  => "messages.togglePin"
				])->then(function (array $response) use ($timeout) {

					PromiseUtils::expectDataResponse($response, 'toggle pin');

					return $this->awaitPinConfirmation($timeout);

				});

			});

		}

		/**
		 * Waits for the server's messageupdate subscription event to confirm
		 * the new pinned state after a togglePin mutation.
		 *
		 * @param int $timeout Seconds to wait before rejecting.
		 * @return PromiseInterface Resolves with a bool indicating the new pinned state.
		 */
		private function awaitPinConfirmation(int $timeout): PromiseInterface {

			return new Promise(function ($resolve, $reject) use ($timeout) {

				$normalizedId = (string) $this->id;
				$listener     = null;
				$timer        = null;

				$cleanup = function () use (&$listener, &$timer) {
					$this->sharkord->removeListener('messageupdate', $listener);
					if ($timer) {
						$this->sharkord->loop->cancelTimer($timer);
						$timer = null;
					}
				};

				$listener = function (Message $updated) use ($resolve, $normalizedId, &$cleanup) {
					if ((string) $updated->id === $normalizedId) {
						$cleanup();
						$resolve((bool) $updated->pinned);
					}
				};

				$timer = $this->sharkord->loop->addTimer($timeout, function () use ($reject, $normalizedId, &$cleanup) {
					$cleanup();
					$reject(new \RuntimeException(
						"togglePin timed out waiting for onUpdate confirmation for message ID {$normalizedId}."
					));
				});

				$this->sharkord->on('messageupdate', $listener);

			});

		}
		
		/**
		 * Checks whether this message is currently pinned.
		 *
		 * @return bool True if the message is pinned, false otherwise.
		 *
		 * @example
		 * ```php
		 * if ($message->isPinned()) {
		 *     echo "This message is pinned.\n";
		 * }
		 * ```
		 */
		public function isPinned(): bool {
			return (bool)($this->attributes['pinned'] ?? false);
		}
		
		/**
		 * Returns a complete array of the message data, including
		 * fully expanded User, Channel, and Server objects for debugging.
		 *
		 * The base attributes array (including raw scalar fields such as `reactions`)
		 * is always present in the return value — expanded keys are layered on top.
		 * This makes `toArray()['reactions'] ?? []` a safe way to read the raw
		 * reactions array before it is wrapped into a Reactions collection.
		 *
		 * @return array
		 *
		 * @example
		 * ```php
		 * // Full debug dump
		 * var_dump($message->toArray());
		 *
		 * // Read the raw reactions array directly
		 * $raw = $message->toArray()['reactions'] ?? [];
		 * ```
		 */
		public function toArray(): array {
			
			// 1. Grab the base message data
			$debugData = $this->attributes;

			// 2. If a channel exists, fetch it and turn it into an array
			if ($this->channel) {
				$debugData['channel_expanded'] = $this->channel->toArray();
			}

			// 3. If a user exists, fetch them and turn them into an array
			if ($this->author) {
				$debugData['user_expanded'] = $this->author->toArray();
			}

			// 4. If a server exists, fetch it and turn it into an array
			if ($this->server) {
				$debugData['server_expanded'] = $this->server->toArray();
			}

			return $debugData;
			
		}
		
		/**
		 * Determines whether this message contains any user mentions.
		 *
		 * @return bool True if the message mentions one or more users, false otherwise.
		 *
		 * @example
		 * ```php
		 * if ($message->hasMentions()) {
		 *     echo "Someone was mentioned!\n";
		 * }
		 * ```
		 */
		public function hasMentions(): bool {
			
			return !empty($this->attributes['mentionedUserIds']);
			
		}
		
		/**
		 * Validates whether a given string is exactly one single emoji.
		 *
		 * @param string $emoji The string to validate.
		 * @return bool Returns true if the string is exactly one valid emoji sequence, false otherwise.
		 */
		private function isEmoji(string $emoji): bool {
			
			$pattern = '/^\p{Extended_Pictographic}[\x{FE0F}\p{M}\x{1F3FB}-\x{1F3FF}]*(?:\x{200D}\p{Extended_Pictographic}[\x{FE0F}\p{M}\x{1F3FB}-\x{1F3FF}]*)*$/u';
			return preg_match($pattern, $emoji) === 1;
			
		}
		
		/**
		 * Turns the visual emoji into the text name.
		 *
		 * @param string $emoji The emoji to convert.
		 * @return string Returns the text string value of the emoji.
		 */
		private function emojiToText(string $emoji): string {
			
			$unicodeName = LitEmoji::encodeShortcode($emoji);
			return str_replace(array(' ', ':'), array('_', ''), strtolower($unicodeName));
			
		}

		/**
		 * Parses all user mention spans from a raw HTML content string.
		 *
		 * Extracts the data-user-id attribute from any element matching the
		 * Sharkord mention format before HTML tags are stripped from content.
		 *
		 * @param string $html The raw HTML content string.
		 * @return array<int> An array of unique integer user IDs found in the content.
		 */
		private function parseMentionedUserIds(string $html): array {

			$ids = [];

			if (!preg_match_all('/<[^>]+data-type=["\']mention["\'][^>]+data-user-id=["\'](\d+)["\'][^>]*>/i', $html, $matches)) {
				return $ids;
			}

			foreach ($matches[1] as $userId) {
				$ids[] = (int)$userId;
			}

			// Return unique IDs in the order they appear
			return array_values(array_unique($ids));

		}
		
		/**
		 * Magic isset check. Allows isset() and empty() to work correctly
		 * against both stored attributes and virtual relational properties.
		 *
		 * @param string $name Property name.
		 * @return bool
		 */
		public function __isset(string $name): bool {

			return match($name) {
				'server'    => $this->sharkord->servers->getFirst() !== null,
				'channel'   => !empty($this->attributes['channelId']) && $this->sharkord->channels->get($this->attributes['channelId']) !== null,
				'author',
				'user'      => !empty($this->attributes['userId']) && $this->sharkord->users->get($this->attributes['userId']) !== null,
				'mentions'  => !empty($this->attributes['mentionedUserIds']),
				'reactions' => !empty($this->attributes['reactions']),
				default     => isset($this->attributes[$name]),
			};

		}

		/**
		 * Magic getter for dynamic properties.
		 *
		 * Resolves virtual relational properties before falling through to the raw
		 * attributes array, so model consumers never need to know how data is stored
		 * internally.
		 *
		 * Virtual properties:
		 *
		 * - $message->server     Returns the first cached Server via ServerManager.
		 * - $message->channel    Resolves the Channel from ChannelManager using channelId.
		 * - $message->author     Resolves the User from UserManager using userId.
		 * - $message->user       Alias for $message->author.
		 * - $message->mentions   Returns an array of resolved User objects for all
		 *                        user mentions found in the message content.
		 * - $message->reactions  Returns a Reactions collection keyed by emoji shortcode,
		 *                        built from the raw reactions array on this message.
		 *
		 * Any other property name is looked up directly in the raw attributes array,
		 * returning null if not present.
		 *
		 * @param string $name Property name.
		 * @return mixed
		 */
		public function __get(string $name): mixed {

			if ($name === 'server') {
				return $this->sharkord->servers->getFirst();
			}

			if ($name === 'channel' && !empty($this->attributes['channelId'])) {
				return $this->sharkord->channels->get($this->attributes['channelId']);
			}

			if (($name === 'author' || $name === 'user') && !empty($this->attributes['userId'])) {
				return $this->sharkord->users->get($this->attributes['userId']);
			}

			if ($name === 'mentions') {
				$users = [];
				foreach ($this->attributes['mentionedUserIds'] ?? [] as $userId) {
					if ($user = $this->sharkord->users->get($userId)) {
						$users[] = $user;
					}
				}
				return $users;
			}

			if ($name === 'reactions') {
				return new Reactions($this->sharkord, $this->attributes['reactions'] ?? []);
			}

			return $this->attributes[$name] ?? null;

		}

	}

?>
<?php

	declare(strict_types=1);

	namespace Sharkord\Models;

	use Sharkord\Sharkord;
	use Sharkord\Builders\MessageBuilder;
	use Sharkord\Permission;
	use Sharkord\ChannelPermissionFlag;
	use Sharkord\Internal\GuardedAsync;
	use Sharkord\Internal\PromiseUtils;
	use React\Promise\PromiseInterface;
	use function React\Promise\all;
	use function React\Promise\reject;

	/**
	 * Class Channel
	 *
	 * Represents a chat channel on the server.
	 *
	 * @property-read Category|null $category The category this channel belongs to.
	 * @package Sharkord\Models
	 */
	class Channel {

		use GuardedAsync;

		/**
		 * @var array Stores all dynamic channel data from the API.
		 */
		private array $attributes = [];

		/**
		 * Channel constructor.
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
		 * Factory method to create a Channel from raw API data.
		 *
		 * @param array    $raw      The raw channel data from the server.
		 * @param Sharkord $sharkord Reference to the main bot instance.
		 * @return self
		 */
		public static function fromArray(array $raw, Sharkord $sharkord): self {
			return new self($sharkord, $raw);
		}

		/**
		 * Updates the channel's information dynamically.
		 *
		 * @internal This method is for internal framework use only. Do not call this directly.
		 * @param array $raw The raw channel data from the server.
		 * @return void
		 */
		public function updateFromArray(array $raw): void {

			$this->attributes = array_merge($this->attributes, $raw);

		}

		// -------------------------------------------------------------------------
		// Messaging
		// -------------------------------------------------------------------------

		/**
		 * Sends a message to this channel.
		 *
		 * Accepts either a plain text string or a {@see MessageBuilder} instance.
		 * When a builder is provided, each queued file is read synchronously and
		 * then all HTTP uploads are dispatched concurrently. If the builder has no
		 * queued files, the upload step is skipped entirely.
		 *
		 * @param string|MessageBuilder $message The message text or a configured MessageBuilder.
		 * @return PromiseInterface Resolves with true on success, rejects on failure.
		 *
		 * @example
		 * ```php
		 * // Plain text
		 * $sharkord->channels->get('general')->sendMessage('Hello, world!');
		 * ```
		 *
		 * @example
		 * ```php
		 * // With attachments via MessageBuilder
		 * $builder = \Sharkord\Builders\MessageBuilder::create()
		 *     ->setContent('Here are your files!')
		 *     ->addFile('/tmp/photo.jpg')
		 *     ->addFile('/tmp/report.pdf');
		 *
		 * $sharkord->channels->get('media')->sendMessage($builder);
		 * ```
		 */
		public function sendMessage(string|MessageBuilder $message): PromiseInterface {

			if ($message instanceof MessageBuilder) {

				$html         = $message->buildHtml();
				$pendingFiles = $message->getPendingFiles();

				if (empty($pendingFiles)) {
					return $this->dispatchMessage($html, []);
				}

				$uploads = array_map(
					fn(array $file) => $this->uploadFile($file['path'], $file['mime']),
					$pendingFiles
				);

				return all($uploads)->then(
					fn(array $fileIds) => $this->dispatchMessage($html, $fileIds)
				);

			}

			return $this->dispatchMessage("<p>" . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "</p>", []);

		}

		/**
		 * Sends a single typing indicator signal to this channel.
		 *
		 * The indicator will be visible to other users for approximately 800ms.
		 * For longer operations, use sendTypingWhile() instead.
		 *
		 * @return PromiseInterface Resolves with true on success, rejects on failure.
		 *
		 * @example
		 * ```php
		 * $channel->sendTyping()->then(function() {
		 *     echo "Typing indicator sent.\n";
		 * });
		 * ```
		 */
		public function sendTyping(): PromiseInterface {

			return $this->sharkord->gateway->sendRpc("mutation", [
				"input" => ["channelId" => $this->id],
				"path"  => "messages.signalTyping",
			])->then(fn(array $r) => PromiseUtils::expectDataResponse($r, 'send typing indicator'));

		}

		/**
		 * Sends a repeating typing indicator for the duration of a pending Promise.
		 *
		 * Fires a typing signal immediately and then every 700ms until the given
		 * Promise resolves or rejects, ensuring the indicator stays visible throughout.
		 * The result or rejection of the given Promise is passed through transparently.
		 *
		 * @param PromiseInterface $promise The operation to show a typing indicator for.
		 * @return PromiseInterface Resolves or rejects with the same value as the given Promise.
		 *
		 * @example
		 * ```php
		 * // Show typing while fetching data from an external API
		 * $channel->sendTypingWhile(
		 *     $httpClient->get('https://api.example.com/data')
		 * )->then(function($response) use ($channel) {
		 *     $channel->sendMessage("Got the data: {$response}");
		 * });
		 * ```
		 */
		public function sendTypingWhile(PromiseInterface $promise): PromiseInterface {

			$sendTypingSafe = function () {
				$this->sendTyping()->catch(function (mixed $reason) {
					$this->sharkord->logger->warning(
						"Typing indicator failed: " . PromiseUtils::reasonToString($reason)
					);
				});
			};

			$sendTypingSafe();

			$timer = $this->sharkord->loop->addPeriodicTimer(0.7, $sendTypingSafe);

			$stop = function () use ($timer) {
				$this->sharkord->loop->cancelTimer($timer);
			};

			return $promise->then(
				function ($value) use ($stop) {
					$stop();
					return $value;
				},
				function (mixed $reason) use ($stop) {
					$stop();
					throw $reason instanceof \Throwable
						? $reason
						: new \RuntimeException(PromiseUtils::reasonToString($reason));
				}
			);

		}

		/**
		 * Marks all messages in this channel (or DM thread) as read.
		 *
		 * @return PromiseInterface Resolves with true on success, rejects on failure.
		 *
		 * @example
		 * ```php
		 * $sharkord->channels->get('general')->markAsRead();
		 * ```
		 */
		public function markAsRead(): PromiseInterface {

			return $this->sharkord->gateway->sendRpc("mutation", [
				"input" => ["channelId" => $this->id],
				"path"  => "channels.markAsRead",
			])->then(fn(array $r) => PromiseUtils::expectDataResponse($r, 'mark channel as read'));

		}

		/**
		 * Uploads a local file to the Sharkord storage endpoint.
		 *
		 * Reads the file at $filePath synchronously and posts it as a raw binary
		 * stream to `/upload`. Resolves with the server-assigned file UUID, which
		 * is then queued internally by {@see \Sharkord\Builders\MessageBuilder} when
		 * building a message with attachments.
		 *
		 * The MIME type is detected automatically via mime_content_type() when
		 * omitted. If detection fails, `application/octet-stream` is used as a safe
		 * fallback.
		 *
		 * Note: file_get_contents() is synchronous and will block the event loop
		 * for the duration of the read. Avoid uploading very large files without
		 * considering the impact on other pending operations.
		 *
		 * @param string      $filePath The absolute or relative path to the file to upload.
		 * @param string|null $mimeType MIME type override. Detected automatically when null.
		 * @return PromiseInterface Resolves with the file UUID string, rejects on failure.
		 *
		 * @example
		 * ```php
		 * // Upload a file and retrieve its UUID for use elsewhere.
		 * $sharkord->channels->get('media')
		 *     ->uploadFile('/tmp/screenshot.png')
		 *     ->then(function(string $fileId) {
		 *         echo "File ID: {$fileId}\n";
		 *     });
		 * ```
		 */
		public function uploadFile(string $filePath, ?string $mimeType = null): PromiseInterface {

			if (!is_file($filePath) || !is_readable($filePath)) {
				return reject(new \RuntimeException("File is not readable: {$filePath}"));
			}

			$contents = file_get_contents($filePath);

			if ($contents === false) {
				return reject(new \RuntimeException("Failed to read file contents: {$filePath}"));
			}

			$fileName  = basename($filePath);
			$mimeType ??= mime_content_type($filePath) ?: 'application/octet-stream';

			return $this->sharkord->http->upload($contents, $fileName, $mimeType);

		}

		/**
		 * Dispatches a `messages.send` mutation with a pre-built HTML body and an already-resolved file UUID list.
		 *
		 * The single authoritative send path. All public send methods funnel through here.
		 * Callers are responsible for constructing the HTML string — this method performs
		 * no escaping. Use {@see MessageBuilder::buildHtml()} or wrap plain text manually.
		 *
		 * @param string   $html    The fully constructed HTML body (e.g. '<p>Hello!</p>').
		 * @param string[] $fileIds Pre-resolved file UUIDs from the upload endpoint.
		 * @return PromiseInterface Resolves with true on success, rejects on failure.
		 */
		private function dispatchMessage(string $html, array $fileIds): PromiseInterface {

			return $this->sharkord->gateway->sendRpc("mutation", [
				"input" => [
					"content"   => $html,
					"channelId" => $this->id,
					"files"     => $fileIds,
				],
				"path" => "messages.send",
			])->then(fn(array $r) => PromiseUtils::expectDataResponse($r, 'send message'));

		}

		// -------------------------------------------------------------------------
		// Channel Management
		// -------------------------------------------------------------------------

		/**
		 * Fetches the latest channel data from the server and updates the local cache.
		 *
		 * Useful after making changes to a channel to confirm the server's current state.
		 * The cached Channel model is updated in-place; any existing references remain valid.
		 *
		 * @return PromiseInterface Resolves with the updated Channel model, rejects on failure.
		 *
		 * @example
		 * ```php
		 * $channel->edit('Renamed Channel')->then(function() use ($channel) {
		 *     return $channel->fetch();
		 * })->then(function(\Sharkord\Models\Channel $channel) {
		 *     echo "Server confirms name: {$channel->name}\n";
		 * });
		 * ```
		 */
		public function fetch(): PromiseInterface {

			return $this->sharkord->gateway->sendRpc("query", [
				"input" => ["channelId" => $this->id],
				"path"  => "channels.get",
			])->then(function (array $response) {

				$raw = $response['data']
					?? throw new \RuntimeException(
						"channels.get response missing 'data' for channel ID {$this->id}."
					);

				$this->updateFromArray($raw);

				return $this;

			});

		}

		/**
		 * Edits this channel's name, topic, and/or visibility.
		 *
		 * Requires the MANAGE_CHANNELS permission.
		 *
		 * @param string      $name    The new channel name.
		 * @param string|null $topic   The new channel topic, or null to leave it unchanged.
		 * @param bool|null   $private Whether the channel should be private, or null to leave it unchanged.
		 * @return PromiseInterface Resolves with true on success, rejects on failure.
		 *
		 * @example
		 * ```php
		 * // Rename and set a topic
		 * $sharkord->channels->get('general')->edit('general', 'Welcome to general chat!');
		 *
		 * // Make a channel private
		 * $sharkord->channels->get('staff')->edit('staff', null, true);
		 *
		 * // Rename, set a topic, and make private in one call
		 * $sharkord->channels->get('announcements')->edit('news', 'Official news feed.', true);
		 * ```
		 */
		public function edit(string $name, ?string $topic = null, ?bool $private = null): PromiseInterface {

			return $this->guardedAsync(function () use ($name, $topic, $private) {

				$this->sharkord->guard->requirePermission(Permission::MANAGE_CHANNELS);

				$input = [
					"channelId" => $this->id,
					"name"      => $name,
				];

				if ($topic !== null) {
					$input['topic'] = $topic;
				}

				if ($private !== null) {
					$input['private'] = $private;
				}

				return $this->sharkord->gateway->sendRpc("mutation", [
					"input" => $input,
					"path"  => "channels.update",
				])->then(fn(array $r) => PromiseUtils::expectDataResponse($r, 'edit channel'));

			});

		}

		/**
		 * Permanently deletes this channel from the server.
		 *
		 * Requires the MANAGE_CHANNELS permission. The channel is removed from the
		 * local cache once the server emits the corresponding channeldelete event.
		 *
		 * @return PromiseInterface Resolves with true on success, rejects on failure.
		 *
		 * @example
		 * ```php
		 * $sharkord->channels->get('old-channel')->delete()->then(function() {
		 *     echo "Channel deleted.\n";
		 * });
		 * ```
		 */
		public function delete(): PromiseInterface {

			return $this->guardedAsync(function () {

				$this->sharkord->guard->requirePermission(Permission::MANAGE_CHANNELS);

				return $this->sharkord->gateway->sendRpc("mutation", [
					"input" => ["channelId" => $this->id],
					"path"  => "channels.delete",
				])->then(fn(array $r) => PromiseUtils::expectDataResponse($r, 'delete channel'));

			});

		}

		// -------------------------------------------------------------------------
		// Channel Permissions
		// -------------------------------------------------------------------------

		/**
		 * Retrieves all current role and user permission entries for this channel.
		 *
		 * Requires the MANAGE_CHANNEL_PERMISSIONS permission.
		 *
		 * Resolves with an associative array containing:
		 * - `rolePermissions` — array of {@see ChannelRolePermission} objects, one per flag per role.
		 * - `userPermissions` — raw array of user-level overrides (not yet modelled).
		 *
		 * @return PromiseInterface Resolves with array{rolePermissions: ChannelRolePermission[], userPermissions: array}, rejects on failure.
		 *
		 * @example
		 * ```php
		 * $channel->getPermissions()->then(function(array $permissions) {
		 *     foreach ($permissions['rolePermissions'] as $entry) {
		 *         $state = $entry->allow ? 'allow' : 'deny';
		 *         echo "Role {$entry->roleId} — {$entry->permission->value}: {$state}\n";
		 *     }
		 * });
		 * ```
		 */
		public function getPermissions(): PromiseInterface {

			return $this->guardedAsync(function () {

				$this->sharkord->guard->requirePermission(Permission::MANAGE_CHANNEL_PERMISSIONS);

				// Note: channels.getPermissions uses "mutation" wire type — this is intentional
				// and matches observed server behaviour, despite being a read operation.
				return $this->sharkord->gateway->sendRpc("mutation", [
					"input" => ["channelId" => $this->id],
					"path"  => "channels.getPermissions",
				])->then(function (array $response) {

					$data = $response['data']
						?? throw new \RuntimeException(
							"channels.getPermissions response missing 'data'."
						);

					$rolePermissions = array_map(
						fn(array $raw) => ChannelRolePermission::fromArray($raw),
						$data['rolePermissions'] ?? []
					);

					return [
						'rolePermissions' => $rolePermissions,
						'userPermissions' => $data['userPermissions'] ?? [],
					];

				});

			});

		}

		/**
		 * Adds a role to this channel's permission set, initialising all flags at their defaults.
		 *
		 * This must be called before setRolePermissions() when granting a role access to a
		 * channel for the first time. Subsequent permission changes should use setRolePermissions().
		 *
		 * Requires the MANAGE_CHANNEL_PERMISSIONS permission.
		 *
		 * @param int $roleId The ID of the role to add.
		 * @return PromiseInterface Resolves with true on success, rejects on failure.
		 *
		 * @example
		 * ```php
		 * $channel->addRolePermission(2)->then(function() use ($channel) {
		 *     return $channel->setRolePermissions(
		 *         2,
		 *         \Sharkord\ChannelPermissionFlag::VIEW_CHANNEL,
		 *         \Sharkord\ChannelPermissionFlag::SEND_MESSAGES,
		 *     );
		 * });
		 * ```
		 */
		public function addRolePermission(int $roleId): PromiseInterface {

			return $this->guardedAsync(function () use ($roleId) {

				$this->sharkord->guard->requirePermission(Permission::MANAGE_CHANNEL_PERMISSIONS);

				return $this->sharkord->gateway->sendRpc("mutation", [
					"input" => [
						"channelId" => $this->id,
						"roleId"    => $roleId,
						"isCreate"  => true,
					],
					"path" => "channels.updatePermissions",
				])->then(fn(array $r) => PromiseUtils::expectDataResponse($r, "add role {$roleId} to channel permissions"));

			});

		}

		/**
		 * Sets the allowed permission flags for a role on this channel.
		 *
		 * Replaces the role's current permission set with the provided flags. Any flag
		 * not included in the list will be set to denied. The role must already have been
		 * added via addRolePermission() before calling this method.
		 *
		 * Requires the MANAGE_CHANNEL_PERMISSIONS permission.
		 *
		 * @param int                   $roleId       The ID of the role to update.
		 * @param ChannelPermissionFlag ...$permissions One or more permission flags to allow.
		 * @return PromiseInterface Resolves with true on success, rejects on failure.
		 *
		 * @example
		 * ```php
		 * $channel->setRolePermissions(
		 *     2,
		 *     \Sharkord\ChannelPermissionFlag::VIEW_CHANNEL,
		 *     \Sharkord\ChannelPermissionFlag::SEND_MESSAGES,
		 *     \Sharkord\ChannelPermissionFlag::JOIN,
		 * );
		 * ```
		 */
		public function setRolePermissions(int $roleId, ChannelPermissionFlag ...$permissions): PromiseInterface {

			return $this->guardedAsync(function () use ($roleId, $permissions) {

				$this->sharkord->guard->requirePermission(Permission::MANAGE_CHANNEL_PERMISSIONS);

				return $this->sharkord->gateway->sendRpc("mutation", [
					"input" => [
						"channelId"   => $this->id,
						"roleId"      => $roleId,
						"permissions" => array_map(fn(ChannelPermissionFlag $f) => $f->value, $permissions),
					],
					"path" => "channels.updatePermissions",
				])->then(fn(array $r) => PromiseUtils::expectDataResponse($r, "update permissions for role {$roleId}"));

			});

		}

		/**
		 * Removes a role entirely from this channel's permission set.
		 *
		 * All per-flag entries for the role are deleted. Requires the
		 * MANAGE_CHANNEL_PERMISSIONS permission.
		 *
		 * @param int $roleId The ID of the role to remove.
		 * @return PromiseInterface Resolves with true on success, rejects on failure.
		 *
		 * @example
		 * ```php
		 * $channel->removeRolePermission(2)->then(function() {
		 *     echo "Role removed from channel.\n";
		 * });
		 * ```
		 */
		public function removeRolePermission(int $roleId): PromiseInterface {

			return $this->guardedAsync(function () use ($roleId) {

				$this->sharkord->guard->requirePermission(Permission::MANAGE_CHANNEL_PERMISSIONS);

				return $this->sharkord->gateway->sendRpc("mutation", [
					"input" => [
						"roleId"    => $roleId,
						"channelId" => $this->id,
					],
					"path" => "channels.deletePermissions",
				])->then(fn(array $r) => PromiseUtils::expectDataResponse($r, "remove role {$roleId} from channel permissions"));

			});

		}

		// -------------------------------------------------------------------------
		// Utilities
		// -------------------------------------------------------------------------

		/**
		 * Returns all the attributes as a plain array. Useful for debugging.
		 *
		 * @return array
		 *
		 * @example
		 * ```php
		 * var_dump($channel->toArray());
		 * ```
		 */
		public function toArray(): array {

			return $this->attributes;

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
				'category' => !empty($this->attributes['categoryId']) && $this->sharkord->categories->get($this->attributes['categoryId']) !== null,
				default    => isset($this->attributes[$name]),
			};

		}

		/**
		 * Magic getter. Triggered when accessing any property not explicitly defined.
		 *
		 * Virtual properties:
		 * - $channel->category  Returns the resolved Category via CategoryManager.
		 *
		 * Any other name is looked up directly in the raw attributes array.
		 *
		 * @param string $name Property name.
		 * @return mixed
		 */
		public function __get(string $name): mixed {

			if ($name === 'category' && !empty($this->attributes['categoryId'])) {
				return $this->sharkord->categories->get($this->attributes['categoryId']);
			}

			return $this->attributes[$name] ?? null;

		}

	}

?>
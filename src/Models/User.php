<?php

	declare(strict_types=1);

	namespace Sharkord\Models;
	
	use Sharkord\Sharkord;
	use Sharkord\Permission;
	use Sharkord\Internal\GuardedAsync;
	
	use React\Promise\PromiseInterface;

	/**
	 * Class User
	 *
	 * Represents a user entity on the server.
	 *
	 * @property-read array $roles The roles assigned to this user.
	 * @package Sharkord\Models
	 */
	class User {
		
		use GuardedAsync;
		
		/**
		 * @var array Stores all dynamic user data from the API
		 */
		private array $attributes = [];

		/**
		 * User constructor.
		 *
		 * @param Sharkord $sharkord Reference to the bot instance.
		 * @param array    $rawData  The raw array of data from the API.
		 */
		public function __construct(
			private Sharkord $sharkord,
			array $rawData
		) {
			$this->updateFromArray($rawData);
		}
		
		/**
		 * Factory method to create a User from raw API data.
		 *
		 * @param array    $raw      The raw user data from the server.
		 * @param Sharkord $sharkord Reference to the main bot instance.
		 * @return self
		 */
		public static function fromArray(array $raw, Sharkord $sharkord): self {
			return new self($sharkord, $raw);
		}

		/**
		 * Updates the user's information dynamically.
		 *
		 * @internal This method is for internal framework use only. Do not call this directly.
		 * @param array $raw The raw user data from the server.
		 * @return void
		 */
		public function updateFromArray(array $raw): void {
			
			// 1. Throw away the heavy items we don't want to store
			unset($raw['avatar'], $raw['banner']);

			// 2. Default the status to offline if it wasn't provided in the payload
			if (!isset($raw['status']) && !isset($this->attributes['status'])) {
				$raw['status'] = 'offline';
			}

			// 3. Merge the new data into our magic backpack (attributes array)
			$this->attributes = array_merge($this->attributes, $raw);
			
		}
		
		/**
		 * Updates the user's status specifically.
		 *
		 * @param string $status The new status.
		 * @return void
		 */
		public function updateStatus(string $status): void {
			$this->attributes['status'] = $status;
		}
		
		/**
		 * Determine if the user possesses a specific permission through any of their roles.
		 *
		 * @param Permission $permission The permission enum case to check.
		 * @return bool True if any of the user's roles have the permission, false otherwise.
		 *
		 * @example
		 * ```php
		 * if ($user->hasPermission(\Sharkord\Permission::MANAGE_CHANNELS)) {
		 *     echo "{$user->name} can manage channels.\n";
		 * }
		 * ```
		 */
		public function hasPermission(Permission $permission): bool {
			
			$permissions = $this->permissions;		
			return in_array($permission->value, $permissions, true);
			
		}
		
		/**
		 * Checks if the user is a server owner.
		 *
		 * @return bool True if the user is an owner, false otherwise.
		 *
		 * @example
		 * ```php
		 * if ($user->isOwner()) {
		 *     echo "{$user->name} is a server owner.\n";
		 * }
		 * ```
		 */
		public function isOwner(): bool {
			
			return $this->hasRole(1);
			
		}
		
		/**
		 * Checks if the user has a specific role via their assigned role ids.
		 *
		 * @param int $roleId The role id to check.
		 * @return bool True if the user has the role, false otherwise.
		 *
		 * @example
		 * ```php
		 * if ($user->hasRole(3)) {
		 *     echo "{$user->name} has the Moderator role.\n";
		 * }
		 * ```
		 */
		public function hasRole(int $roleId): bool {

			return !empty($this->roleIds) && in_array($roleId, $this->roleIds, false);

		}
		
		/**
		 * Bans a user from the server.
		 *
		 * @param string $reason The reason for the ban.
		 * @return PromiseInterface Resolves on success, rejects on failure.
		 *
		 * @throws \RuntimeException If the bot lacks MANAGE_USERS or the target is the server owner.
		 *
		 * @example
		 * ```php
		 * $user->ban('Spamming in #general')->then(function() use ($user) {
		 *     echo "{$user->name} has been banned.\n";
		 * });
		 * ```
		 */
		public function ban(string $reason = 'No reason given.'): PromiseInterface {

			return $this->guardedAsync(function () use ($reason) {

				$this->sharkord->guard->requirePermission(Permission::MANAGE_USERS);
				$this->sharkord->guard->requireNotOwner($this);

				return $this->sharkord->gateway->sendRpc("mutation", [
					"input" => ["userId" => $this->id, "reason" => $reason],
					"path"  => "users.ban"
				]);

			});

		}

		/**
		 * Unbans a user from the server.
		 *
		 * @return PromiseInterface Resolves on success, rejects on failure.
		 *
		 * @throws \RuntimeException If the bot lacks MANAGE_USERS.
		 *
		 * @example
		 * ```php
		 * $user->unban()->then(function() use ($user) {
		 *     echo "{$user->name} has been unbanned.\n";
		 * });
		 * ```
		 */
		public function unban(): PromiseInterface {

			return $this->guardedAsync(function () {

				$this->sharkord->guard->requirePermission(Permission::MANAGE_USERS);

				return $this->sharkord->gateway->sendRpc("mutation", [
					"input" => ["userId" => $this->id],
					"path"  => "users.unban"
				]);

			});

		}
		
		/**
		 * Kicks a user from the server.
		 *
		 * @param string $reason The reason for the kick.
		 * @return PromiseInterface Resolves on success, rejects on failure.
		 *
		 * @throws \RuntimeException If the bot lacks MANAGE_USERS or the target is the server owner.
		 *
		 * @example
		 * ```php
		 * $user->kick('Violating server rules')->then(function() use ($user) {
		 *     echo "{$user->name} has been kicked.\n";
		 * });
		 * ```
		 */
		public function kick(string $reason = 'No reason given.'): PromiseInterface {

			return $this->guardedAsync(function () use ($reason) {

				$this->sharkord->guard->requirePermission(Permission::MANAGE_USERS);
				$this->sharkord->guard->requireNotOwner($this);

				return $this->sharkord->gateway->sendRpc("mutation", [
					"input" => ["userId" => $this->id, "reason" => $reason],
					"path"  => "users.kick"
				]);

			});

		}
		
		/**
		 * Deletes a user from the server.
		 *
		 * @param bool  $wipe Whether to delete all associated user data (posts, files, emoji, etc.).
		 * @return PromiseInterface Resolves on success, rejects on failure.
		 *
		 * @throws \RuntimeException If the bot lacks MANAGE_USERS or the target is the server owner.
		 *
		 * @example
		 * ```php
		 * $user->delete(wipe: true)->then(function() use ($user) {
		 *     echo "{$user->name} and all their data have been removed.\n";
		 * });
		 * ```
		 */
		public function delete(bool $wipe = false): PromiseInterface {

			return $this->guardedAsync(function () use ($wipe) {

				$this->sharkord->guard->requirePermission(Permission::MANAGE_USERS);
				$this->sharkord->guard->requireNotOwner($this);

				return $this->sharkord->gateway->sendRpc("mutation", [
					"input" => ["userId" => $this->id, "wipe" => $wipe],
					"path"  => "users.delete"
				]);

			});

		}
		
		/**
		 * Opens a direct message channel with this user.
		 *
		 * Delegates to DmManager::open() and resolves to a Channel object
		 * that can be used for sending messages, typing indicators, and more.
		 *
		 * @return PromiseInterface Resolves with a Channel object, rejects on failure.
		 *
		 * @example
		 * ```php
		 * $sharkord->on('message', function(Message $message) use ($sharkord) {
		 *     $message->author->openDm()->then(function(Channel $channel) {
		 *         $channel->sendMessage("Hey, I got your message!");
		 *         $channel->markAsRead();
		 *     });
		 * });
		 * ```
		 */
		public function openDm(): PromiseInterface {

			return $this->sharkord->dms->open($this->id);

		}
		
		/**
		 * Opens a DM channel with this user and sends a message in one call.
		 *
		 * This is a convenience wrapper around openDm() + Channel::sendMessage().
		 * Use openDm() directly when you need access to the Channel object itself.
		 *
		 * @param string $text The message content.
		 * @return PromiseInterface Resolves on success, rejects on failure.
		 *
		 * @example
		 * ```php
		 * $sharkord->on('message', function(Message $message) {
		 *     if ($message->content === '!hello') {
		 *         $message->author->sendDm("Hey! This is a private message just for you.");
		 *     }
		 * });
		 * ```
		 */
		public function sendDm(string $text): PromiseInterface {

			return $this->openDm()->then(
				fn(Channel $channel) => $channel->sendMessage($text)
			);

		}
		
		/**
		 * Returns all the attributes as an array. Perfect for debugging!
		 *
		 * @return array
		 *
		 * @example
		 * ```php
		 * var_dump($user->toArray());
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
				'server'      => $this->sharkord->servers->getFirst() !== null,
				'roles'       => !empty($this->attributes['roleIds']),
				'permissions' => !empty($this->attributes['roleIds']),
				default       => isset($this->attributes[$name]),
			};

		}

		/**
		 * Magic getter. This is triggered whenever you try to access a property 
		 * that isn't explicitly defined (e.g., $user->bio or $user->id).
		 *
		 * @param string $name Property name.
		 * @return mixed
		 */
		public function __get(string $name): mixed {
			
			if ($name === 'server' && $this->sharkord) {
				// We use the bot instance to ask the ServerManager for the server object
				return $this->sharkord->servers->getFirst();
			}
			
			// Handle the special 'roles' request
			if ($name === 'roles' && $this->sharkord) {
				$roles = [];
				$roleIds = $this->attributes['roleIds'] ?? [];
				foreach ($roleIds as $roleId) {
					if ($role = $this->sharkord->roles->get($roleId)) {
						$roles[] = $role;
					}
				}
				return $roles;
			}
			
			if ($name === 'permissions' && $this->sharkord) {
				
				$permissions = [];
				$roleIds = $this->attributes['roleIds'] ?? [];
				foreach ($roleIds as $roleId) {
					if ($role = $this->sharkord->roles->get($roleId)) {
						$permissions = array_merge($permissions, $role->permissions ?? []);
					}
				}
				return $permissions;
				
			}
			
			// If it's not 'roles', look inside our magic backpack!
			return $this->attributes[$name] ?? null;
			
		}

	}
	
?>
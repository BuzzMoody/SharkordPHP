<?php

	declare(strict_types=1);

	namespace Sharkord\Internal;

	use Sharkord\Models\User;
	use Sharkord\Permission;
	use Sharkord\Sharkord;

	/**
	 * Class Guard
	 *
	 * Provides centralised pre-condition checks for bot permission and ownership
	 * validation. All methods throw on failure and return void on success, keeping
	 * them composable and free of Promise awareness.
	 *
	 * @package Sharkord\Internal
	 */
	class Guard {

		/**
		 * Guard constructor.
		 *
		 * @param Sharkord $sharkord The main bot instance.
		 */
		public function __construct(
			private readonly Sharkord $sharkord
		) {}

		/**
		 * Asserts that the bot entity has been set.
		 *
		 * @return void
		 * @throws \RuntimeException If the bot entity is not set.
		 *
		 * @example
		 * ```php
		 * $sharkord->guard->requireBot();
		 * ```
		 */
		public function requireBot(): void {

			if (!$this->sharkord->bot) {
				throw new \RuntimeException("Bot entity not set.");
			}

		}

		/**
		 * Asserts that the bot holds a specific permission.
		 *
		 * @param Permission $permission The permission to require.
		 * @return void
		 * @throws \RuntimeException If the bot entity is not set or lacks the permission.
		 *
		 * @example
		 * ```php
		 * $sharkord->guard->requirePermission(\Sharkord\Permission::MANAGE_CHANNELS);
		 * ```
		 */
		public function requirePermission(Permission $permission): void {

			$this->requireBot();

			if (!$this->sharkord->bot->hasPermission($permission)) {
				throw new \RuntimeException(
					"Missing {$permission->value} permission."
				);
			}

		}

		/**
		 * Asserts that the bot either owns the resource or holds a fallback permission.
		 *
		 * Used for actions like edit/delete where a user may act on their own content
		 * without needing elevated permissions.
		 *
		 * @param int|string|null $resourceOwnerId The ID of the resource's owner.
		 * @param Permission      $permission      The fallback permission to require if not the owner.
		 * @return void
		 * @throws \RuntimeException If the bot entity is not set, is not the owner, and lacks the permission.
		 *
		 * @example
		 * ```php
		 * $sharkord->guard->requireOwnershipOrPermission(
		 *     $message->author?->id,
		 *     \Sharkord\Permission::MANAGE_MESSAGES,
		 * );
		 * ```
		 */
		public function requireOwnershipOrPermission(int|string|null $resourceOwnerId, Permission $permission): void {

			$this->requireBot();

			$isOwn = $this->sharkord->bot->id === $resourceOwnerId;

			if (!$isOwn && !$this->sharkord->bot->hasPermission($permission)) {
				throw new \RuntimeException(
					"Missing {$permission->value} permission."
				);
			}

		}

		/**
		 * Asserts that the given user is not the server owner.
		 *
		 * Used to guard destructive actions (ban, kick, delete) that cannot
		 * be performed on the server owner.
		 *
		 * @param User $user The user to check.
		 * @return void
		 * @throws \RuntimeException If the user is the server owner.
		 *
		 * @example
		 * ```php
		 * $sharkord->guard->requireNotOwner($user);
		 * ```
		 */
		public function requireNotOwner(User $user): void {

			if ($user->isOwner()) {
				throw new \RuntimeException(
					"Cannot perform this action on {$user->name} as they are the server owner."
				);
			}

		}

	}
	
?>
<?php

	declare(strict_types=1);

	namespace Sharkord\Models;

	use Sharkord\Sharkord;
	use Sharkord\Permission;
	use Sharkord\Internal\GuardedAsync;
	use Sharkord\Internal\PromiseUtils;
	use React\Promise\PromiseInterface;

	/**
	 * Class Role
	 *
	 * Represents a user role on the server.
	 *
	 * @package Sharkord\Models
	 *
	 * @example
	 * ```php
	 * $role = $sharkord->roles->get(3);
	 *
	 * echo $role->name;        // "Admins"
	 * echo $role->color;       // "#0085de"
	 * echo $role->isDefault;   // false
	 * echo $role->isPersistent; // false
	 * ```
	 */
	class Role {

		use GuardedAsync;

		/**
		 * @var array Stores all dynamic role data from the API.
		 */
		private array $attributes = [];

		/**
		 * Role constructor.
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
		 * Factory method to create a Role from raw API data.
		 *
		 * @param array    $raw      The raw role data from the server.
		 * @param Sharkord $sharkord Reference to the main bot instance.
		 * @return self
		 */
		public static function fromArray(array $raw, Sharkord $sharkord): self {
			return new self($sharkord, $raw);
		}

		/**
		 * Updates the Role's information dynamically.
		 *
		 * @internal This method is for internal framework use only. Do not call this directly.
		 * @param array $raw The raw Role data from the server.
		 * @return void
		 */
		public function updateFromArray(array $raw): void {

			$this->attributes = array_merge($this->attributes, $raw);

		}

		/**
		 * Edits this role's name, colour, and permission set.
		 *
		 * Sends the roles.update mutation. The server will push a roles.onUpdate
		 * event that updates the cached model automatically.
		 *
		 * Requires the MANAGE_ROLES permission.
		 *
		 * @param string     $name        The new role name.
		 * @param string     $color       The new role colour as a CSS hex string (e.g. "#ff0000").
		 * @param Permission ...$permissions Zero or more permissions to grant to the role.
		 *                                   Any permission not listed will be revoked. Passing no
		 *                                   permissions will revoke all permissions from the role.
		 * @return PromiseInterface Resolves with true on success, rejects on failure.
		 *
		 * @example
		 * ```php
		 * $sharkord->roles->get(3)?->edit(
		 *     'Moderators',
		 *     '#00aaff',
		 *     \Sharkord\Permission::MANAGE_MESSAGES,
		 *     \Sharkord\Permission::MANAGE_USERS,
		 *     \Sharkord\Permission::PIN_MESSAGES,
		 * )->then(function() {
		 *     echo "Role updated.\n";
		 * });
		 * ```
		 */
		public function edit(string $name, string $color, Permission ...$permissions): PromiseInterface {

			return $this->guardedAsync(function () use ($name, $color, $permissions) {

				$this->sharkord->guard->requirePermission(Permission::MANAGE_ROLES);

				return $this->sharkord->gateway->sendRpc("mutation", [
					"input" => [
						"roleId"      => $this->id,
						"name"        => $name,
						"color"       => $color,
						"permissions" => array_map(fn(Permission $p) => $p->value, $permissions),
					],
					"path" => "roles.update",
				])->then(fn(array $r) => PromiseUtils::expectDataResponse($r, 'edit role'));

			});

		}

		/**
		 * Sets this role as the server default.
		 *
		 * The server will push two roles.onUpdate events: one to unset the previous
		 * default role and one to mark this role as default. Both cached models are
		 * updated automatically via the subscription handler.
		 *
		 * Requires the MANAGE_ROLES permission.
		 *
		 * @return PromiseInterface Resolves with true on success, rejects on failure.
		 *
		 * @example
		 * ```php
		 * $sharkord->roles->get(3)?->setAsDefault()->then(function() {
		 *     echo "Role is now the server default.\n";
		 * });
		 * ```
		 */
		public function setAsDefault(): PromiseInterface {

			return $this->guardedAsync(function () {

				$this->sharkord->guard->requirePermission(Permission::MANAGE_ROLES);

				return $this->sharkord->gateway->sendRpc("mutation", [
					"input" => ["roleId" => $this->id],
					"path"  => "roles.setDefault",
				])->then(fn(array $r) => PromiseUtils::expectDataResponse($r, 'set role as default'));

			});

		}

		/**
		 * Permanently deletes this role from the server.
		 *
		 * Requires the MANAGE_ROLES permission. The role is removed from the local
		 * cache once the server emits the corresponding roles.onDelete event.
		 * Persistent roles (e.g. Owner, default role) cannot be deleted.
		 *
		 * @return PromiseInterface Resolves with true on success, rejects on failure.
		 *
		 * @example
		 * ```php
		 * $sharkord->roles->get(5)?->delete()->then(function() {
		 *     echo "Role deleted.\n";
		 * });
		 * ```
		 */
		public function delete(): PromiseInterface {

			return $this->guardedAsync(function () {

				$this->sharkord->guard->requirePermission(Permission::MANAGE_ROLES);

				return $this->sharkord->gateway->sendRpc("mutation", [
					"input" => ["roleId" => $this->id],
					"path"  => "roles.delete",
				])->then(fn(array $r) => PromiseUtils::expectDataResponse($r, 'delete role'));

			});

		}

		/**
		 * Returns all the attributes as a plain array. Useful for debugging.
		 *
		 * @return array
		 *
		 * @example
		 * ```php
		 * var_dump($sharkord->roles->get(3)?->toArray());
		 * ```
		 */
		public function toArray(): array {

			return $this->attributes;

		}

		/**
		 * Magic isset check. Allows isset() and empty() to work correctly
		 * against dynamic properties stored in the attributes array.
		 *
		 * @param string $name Property name.
		 * @return bool
		 */
		public function __isset(string $name): bool {

			return isset($this->attributes[$name]);

		}

		/**
		 * Magic getter. Triggered whenever you try to access a property
		 * that isn't explicitly defined (e.g. $role->name or $role->color).
		 *
		 * @param string $name Property name.
		 * @return mixed
		 */
		public function __get(string $name): mixed {

			return $this->attributes[$name] ?? null;

		}

	}

?>
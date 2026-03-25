<?php

	declare(strict_types=1);

	namespace Sharkord\Models;

	use React\Promise\PromiseInterface;
	use Sharkord\Sharkord;
	use Sharkord\Permission;
	use Sharkord\Internal\GuardedAsync;
	use Sharkord\Internal\PromiseUtils;

	/**
	 * Class Invite
	 *
	 * Represents a server invite link.
	 *
	 * Instances are returned by {@see \Sharkord\Managers\InviteManager::getAll()}
	 * and {@see \Sharkord\Managers\InviteManager::create()}, and are cached by
	 * {@see \Sharkord\Collections\Invites}.
	 *
	 * @property-read int         $id         Unique invite ID.
	 * @property-read string      $code       The invite code used in join URLs.
	 * @property-read int         $creatorId  User ID of the user who created the invite.
	 * @property-read int|null    $roleId     ID of the role assigned to users who join via this invite, or null for the default role.
	 * @property-read int|null    $maxUses    Maximum number of uses allowed, or null for unlimited.
	 * @property-read int         $uses       Number of times this invite has been used.
	 * @property-read int|null    $expiresAt  Expiry timestamp in milliseconds, or null if it never expires.
	 * @property-read int         $createdAt  Creation timestamp in milliseconds.
	 * @property-read \Sharkord\Models\User|null $creator The resolved creator User, or null if not in cache.
	 * @property-read \Sharkord\Models\Role|null $role    The resolved role assigned on join, or null.
	 *
	 * @package Sharkord\Models
	 *
	 * @example
	 * ```php
	 * $sharkord->invites->getAll()->then(function (array $invites) {
	 *     foreach ($invites as $invite) {
	 *         $uses    = $invite->maxUses !== null ? "{$invite->uses}/{$invite->maxUses}" : "{$invite->uses}/∞";
	 *         $expiry  = $invite->expiresAt !== null ? date('Y-m-d', (int) ($invite->expiresAt / 1000)) : 'never';
	 *         $creator = $invite->creator?->name ?? "Unknown (ID {$invite->creatorId})";
	 *
	 *         echo "[{$invite->code}] by {$creator} — used {$uses} — expires {$expiry}\n";
	 *     }
	 * });
	 * ```
	 */
	class Invite {

		use GuardedAsync;

		/**
		 * @var array<string, mixed> Raw invite data from the API.
		 */
		private array $attributes = [];

		/**
		 * Invite constructor.
		 *
		 * @param Sharkord             $sharkord Reference to the main bot instance.
		 * @param array<string, mixed> $rawData  The raw invite payload from the API.
		 */
		public function __construct(
			private readonly Sharkord $sharkord,
			array $rawData
		) {
			$this->updateFromArray($rawData);
		}

		/**
		 * Factory method to create an Invite from a raw API data array.
		 *
		 * @param array<string, mixed> $raw      The raw invite payload.
		 * @param Sharkord             $sharkord Reference to the main bot instance.
		 * @return self
		 */
		public static function fromArray(array $raw, Sharkord $sharkord): self {
			return new self($sharkord, $raw);
		}

		/**
		 * Merges new data into the invite model in place.
		 *
		 * Nested `creator` and `role` objects are discarded; those are resolved
		 * at access time from the manager caches via virtual properties.
		 *
		 * @internal
		 * @param array<string, mixed> $raw Raw invite data, may be a partial payload.
		 * @return void
		 */
		public function updateFromArray(array $raw): void {

			unset($raw['creator'], $raw['role']);

			if (($raw['expiresAt'] ?? null) === 0) {
				$raw['expiresAt'] = null;
			}

			if (($raw['maxUses'] ?? null) === 0) {
				$raw['maxUses'] = null;
			}

			$this->attributes = array_merge($this->attributes, $raw);

		}

		/**
		 * Deletes this invite via the `invites.delete` RPC.
		 *
		 * Requires either ownership of the invite (i.e. the bot created it)
		 * or the MANAGE_INVITES permission.
		 *
		 * @return PromiseInterface Resolves with true on success, rejects on failure.
		 *
		 * @example
		 * ```php
		 * $sharkord->invites->getAll()->then(function (array $invites) {
		 *     foreach ($invites as $invite) {
		 *         if ($invite->uses === 0) {
		 *             $invite->delete()->then(function () use ($invite) {
		 *                 echo "Deleted unused invite: {$invite->code}\n";
		 *             });
		 *         }
		 *     }
		 * });
		 * ```
		 */
		public function delete(): PromiseInterface {

			return $this->guardedAsync(function (): PromiseInterface {

				$inviteId = $this->id
					?? throw new \RuntimeException("Cannot delete invite: missing 'id' attribute.");

				$this->sharkord->guard->requireOwnershipOrPermission(
					$this->attributes['creatorId'] ?? null,
					Permission::MANAGE_INVITES
				);

				return $this->sharkord->gateway->sendRpc("mutation", [
					"input" => ["inviteId" => $inviteId],
					"path"  => "invites.delete",
				])->then(function (array $response) use ($inviteId): bool {

					PromiseUtils::expectDataResponse($response, 'delete invite');
					$this->sharkord->invites->collection()->remove($inviteId);
					return true;

				});

			});

		}

		/**
		 * Returns the invite data as a plain array.
		 *
		 * @return array<string, mixed>
		 */
		public function toArray(): array {
			return $this->attributes;
		}

		/**
		 * Magic isset check. Supports virtual relational properties `creator` and `role`.
		 *
		 * @param string $name Property name.
		 * @return bool
		 */
		public function __isset(string $name): bool {

			return match($name) {
				'creator' => !empty($this->attributes['creatorId']) && $this->sharkord->users->get($this->attributes['creatorId']) !== null,
				'role'    => !empty($this->attributes['roleId']) && $this->sharkord->roles->get($this->attributes['roleId']) !== null,
				default   => isset($this->attributes[$name]),
			};

		}

		/**
		 * Magic getter.
		 *
		 * Virtual properties:
		 * - `$invite->creator` Resolves the creator User from the UserManager cache.
		 * - `$invite->role`    Resolves the assigned Role from the RoleManager cache.
		 *
		 * All other names are looked up in the raw attributes array.
		 *
		 * @param string $name Property name.
		 * @return mixed
		 */
		public function __get(string $name): mixed {

			if ($name === 'creator' && !empty($this->attributes['creatorId'])) {
				return $this->sharkord->users->get($this->attributes['creatorId']);
			}

			if ($name === 'role' && !empty($this->attributes['roleId'])) {
				return $this->sharkord->roles->get($this->attributes['roleId']);
			}

			return $this->attributes[$name] ?? null;

		}

	}

?>
<?php

	declare(strict_types=1);

	namespace Sharkord\Models;

	use Sharkord\Sharkord;
	use Sharkord\Permission;
	use Sharkord\Internal\GuardedAsync;
	use Sharkord\Internal\PromiseUtils;
	use React\Promise\PromiseInterface;

	/**
	 * Class Category
	 *
	 * Represents a channel category on the server.
	 *
	 * @package Sharkord\Models
	 *
	 * @example
	 * ```php
	 * // Rename a category
	 * $sharkord->categories->get(3)?->edit('Renamed Category')->then(function() {
	 *     echo "Category renamed.\n";
	 * });
	 *
	 * // Delete a category
	 * $sharkord->categories->get(3)?->delete()->then(function() {
	 *     echo "Category deleted.\n";
	 * });
	 *
	 * // Re-fetch the latest data from the server
	 * $sharkord->categories->get(3)?->fetch()->then(function(\Sharkord\Models\Category $category) {
	 *     echo "Refreshed: {$category->name}\n";
	 * });
	 * ```
	 */
	class Category {

		use GuardedAsync;

		/**
		 * @var array<string, mixed> Stores all dynamic category data from the API.
		 */
		private array $attributes = [];

		/**
		 * Category constructor.
		 *
		 * @param Sharkord $sharkord Reference to the main bot instance.
		 * @param array    $rawData  The raw array of data from the API.
		 */
		public function __construct(
			private readonly Sharkord $sharkord,
			array $rawData
		) {
			$this->updateFromArray($rawData);
		}

		/**
		 * Factory method to create a Category from raw API data.
		 *
		 * @param array    $raw      The raw category data from the server.
		 * @param Sharkord $sharkord Reference to the main bot instance.
		 * @return self
		 */
		public static function fromArray(array $raw, Sharkord $sharkord): self {

			return new self($sharkord, $raw);

		}

		/**
		 * Updates the category's stored attributes in place.
		 *
		 * @internal This method is for internal framework use only. Do not call this directly.
		 * @param array $raw The raw category data from the server.
		 * @return void
		 */
		public function updateFromArray(array $raw): void {

			$this->attributes = array_merge($this->attributes, $raw);

		}

		// -------------------------------------------------------------------------
		// Actions
		// -------------------------------------------------------------------------

		/**
		 * Re-fetches this category's data from the server and updates the local model.
		 *
		 * Useful when you need guaranteed up-to-date data outside of a subscription
		 * event (e.g. after a reorder).
		 *
		 *
		 * @return PromiseInterface Resolves with this Category model (updated in place), rejects on failure.
		 *
		 * @example
		 * ```php
		 * $sharkord->categories->get(3)?->fetch()->then(function(\Sharkord\Models\Category $category) {
		 *     echo "Current position: {$category->position}\n";
		 * });
		 * ```
		 */
		public function fetch(): PromiseInterface {

			return $this->guardedAsync(function () {

				return $this->sharkord->gateway->sendRpc("query", [
					"input" => ["categoryId" => $this->id],
					"path"  => "categories.get",
				])->then(function (array $response) {

					$raw = $response['data']
						?? throw new \RuntimeException(
							"categories.get response missing 'data' for category ID {$this->id}."
						);

					$this->updateFromArray($raw);

					return $this;

				});

			});

		}

		/**
		 * Renames this category.
		 *
		 * The server will push a `categories.onUpdate` event once the change is
		 * accepted. The cached model is updated automatically via the subscription
		 * handler.
		 *
		 * Requires the MANAGE_CATEGORIES permission.
		 *
		 * @param string $name The new category name.
		 * @return PromiseInterface Resolves with true on success, rejects on failure.
		 *
		 * @example
		 * ```php
		 * $sharkord->categories->get(3)?->edit('🏉 Footy Talk')->then(function() {
		 *     echo "Category renamed.\n";
		 * });
		 * ```
		 */
		public function edit(string $name): PromiseInterface {

			return $this->guardedAsync(function () use ($name) {

				$this->sharkord->guard->requirePermission(Permission::MANAGE_CATEGORIES);

				return $this->sharkord->gateway->sendRpc("mutation", [
					"input" => [
						"categoryId" => $this->id,
						"name"       => $name,
					],
					"path" => "categories.update",
				])->then(fn(array $r) => PromiseUtils::expectDataResponse($r, 'edit category'));

			});

		}

		/**
		 * Permanently deletes this category from the server.
		 *
		 * Requires the MANAGE_CATEGORIES permission. The category is removed from
		 * the local cache once the server emits the corresponding categorydelete event.
		 *
		 * @return PromiseInterface Resolves with true on success, rejects on failure.
		 *
		 * @example
		 * ```php
		 * $sharkord->categories->get(7)?->delete()->then(function() {
		 *     echo "Category deleted.\n";
		 * });
		 * ```
		 */
		public function delete(): PromiseInterface {

			return $this->guardedAsync(function () {

				$this->sharkord->guard->requirePermission(Permission::MANAGE_CATEGORIES);

				return $this->sharkord->gateway->sendRpc("mutation", [
					"input" => ["categoryId" => $this->id],
					"path"  => "categories.delete",
				])->then(fn(array $r) => PromiseUtils::expectDataResponse($r, 'delete category'));

			});

		}

		// -------------------------------------------------------------------------
		// Utilities
		// -------------------------------------------------------------------------

		/**
		 * Returns all the attributes as a plain array. Useful for debugging.
		 *
		 * @return array<string, mixed>
		 *
		 * @example
		 * ```php
		 * var_dump($sharkord->categories->get(3)?->toArray());
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
		 * that isn't explicitly defined (e.g. $category->name or $category->position).
		 *
		 * @param string $name Property name.
		 * @return mixed
		 */
		public function __get(string $name): mixed {

			return $this->attributes[$name] ?? null;

		}

	}

?>
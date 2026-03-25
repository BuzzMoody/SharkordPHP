<?php

	declare(strict_types=1);

	namespace Sharkord\Managers;

	use Sharkord\Sharkord;
	use Sharkord\Permission;
	use Sharkord\Internal\GuardedAsync;
	use Sharkord\Internal\PromiseUtils;
	use Sharkord\Collections\Categories as CategoriesCollection;
	use Sharkord\Models\Category;
	use React\Promise\PromiseInterface;

	/**
	 * Class CategoryManager
	 *
	 * Manages category lifecycle events and exposes actions for creating,
	 * reordering, and fetching categories. Delegates all cache storage to a
	 * Categories collection instance.
	 *
	 * Accessible via `$sharkord->categories`.
	 *
	 * @package Sharkord\Managers
	 *
	 * @example
	 * ```php
	 * // Create a new category
	 * $sharkord->categories->add('🎮 Gaming')->then(function(\Sharkord\Models\Category $category) {
	 *     echo "Created category '{$category->name}' (ID: {$category->id})\n";
	 * });
	 *
	 * // Rename an existing category
	 * $sharkord->categories->get(3)?->edit('🏉 Footy Talk');
	 *
	 * // Delete a category
	 * $sharkord->categories->get(7)?->delete();
	 *
	 * // Reorder all categories by their IDs
	 * $sharkord->categories->reorder(1, 7, 3, 5);
	 *
	 * $sharkord->on(\Sharkord\Events::CATEGORY_CREATE, function(\Sharkord\Models\Category $category): void {
	 *     echo "New category created: {$category->name}\n";
	 * });
	 * ```
	 */
	class CategoryManager {

		use GuardedAsync;

		private CategoriesCollection $cache;

		/**
		 * CategoryManager constructor.
		 *
		 * @param Sharkord $sharkord The main bot instance.
		 */
		public function __construct(
			private readonly Sharkord $sharkord
		) {
			$this->cache = new CategoriesCollection($this->sharkord);
		}

		// -------------------------------------------------------------------------
		// Internal event handlers
		// -------------------------------------------------------------------------

		/**
		 * Handles the hydration of a category from the initial join payload.
		 *
		 * @internal
		 * @param array $raw The raw category data.
		 * @return void
		 */
		public function hydrate(array $raw): void {

			$this->cache->add($raw);

		}

		/**
		 * Handles the server-initiated creation of a category.
		 *
		 * Adds the category to the local cache and emits a `categorycreate` event.
		 *
		 * @internal
		 * @param array $raw The raw category data.
		 * @return void
		 *
		 * @example
		 * ```php
		 * $sharkord->on(\Sharkord\Events::CATEGORY_CREATE, function(\Sharkord\Models\Category $category): void {
		 *     echo "Category '{$category->name}' was created.\n";
		 * });
		 * ```
		 */
		public function onCreate(array $raw): void {

			if (!isset($raw['id'])) {
				$this->sharkord->logger->warning("Cannot create category: missing 'id' in data.");
				return;
			}

			$this->cache->add($raw);

			$this->sharkord->emit('categorycreate', [$this->cache->get($raw['id'])]);

		}

		/**
		 * Handles a server-pushed update to a category.
		 *
		 * Updates the cached model in place and emits a `categoryupdate` event.
		 *
		 * @internal
		 * @param array $raw The raw category data.
		 * @return void
		 *
		 * @example
		 * ```php
		 * $sharkord->on(\Sharkord\Events::CATEGORY_UPDATE, function(\Sharkord\Models\Category $category): void {
		 *     echo "Category '{$category->name}' was updated.\n";
		 * });
		 * ```
		 */
		public function onUpdate(array $raw): void {

			if (!isset($raw['id'])) {
				$this->sharkord->logger->warning("Cannot update category: missing 'id' in data.");
				return;
			}

			$category = $this->cache->update($raw);

			if ($category) {
				$this->sharkord->emit('categoryupdate', [$category]);
			}

		}

		/**
		 * Handles the server-initiated deletion of a category.
		 *
		 * Emits a `categorydelete` event with the cached model before removing it.
		 *
		 * @internal
		 * @param int $id The ID of the deleted category.
		 * @return void
		 *
		 * @example
		 * ```php
		 * $sharkord->on(\Sharkord\Events::CATEGORY_DELETE, function(\Sharkord\Models\Category $category): void {
		 *     echo "Category '{$category->name}' was deleted.\n";
		 * });
		 * ```
		 */
		public function onDelete(int $id): void {

			$category = $this->cache->get($id);

			if (!$category) {
				$this->sharkord->logger->error("Category ID {$id} doesn't exist, therefore cannot be deleted.");
				return;
			}

			$this->sharkord->emit('categorydelete', [$category]);
			$this->cache->remove($id);

		}

		// -------------------------------------------------------------------------
		// Public API
		// -------------------------------------------------------------------------

		/**
		 * Creates a new category on the server.
		 *
		 * Sends the `categories.add` mutation, then fetches the full category data via
		 * `categories.get` to hydrate it into the local cache. The returned Category
		 * model is immediately usable.
		 *
		 * Requires the MANAGE_CATEGORIES permission.
		 *
		 * @param string $name The name for the new category.
		 * @return PromiseInterface Resolves with the new Category model, rejects on failure.
		 *
		 * @example
		 * ```php
		 * $sharkord->categories->add('🎮 Gaming')->then(function(\Sharkord\Models\Category $category) {
		 *     echo "Created '{$category->name}' at position {$category->position}\n";
		 * });
		 * ```
		 */
		public function add(string $name): PromiseInterface {

			return $this->guardedAsync(function () use ($name) {

				$this->sharkord->guard->requirePermission(Permission::MANAGE_CATEGORIES);

				return $this->sharkord->gateway->sendRpc("mutation", [
					"input" => ["name" => $name],
					"path"  => "categories.add",
				])->then(function (array $response) {

					$categoryId = $response['data']
						?? throw new \RuntimeException(
							"categories.add response missing 'data' (expected new category ID)."
						);

					return $this->sharkord->gateway->sendRpc("query", [
						"input" => ["categoryId" => (int) $categoryId],
						"path"  => "categories.get",
					])->then(function (array $response) use ($categoryId) {

						$raw = $response['data']
							?? throw new \RuntimeException(
								"categories.get response missing 'data' for category ID {$categoryId}."
							);

						$this->cache->add($raw);

						return $this->cache->get((int) $categoryId)
							?? throw new \RuntimeException(
								"Category ID {$categoryId} was not found in cache after add()."
							);

					});

				});

			});

		}
		
		/**
		 * Returns the cached category IDs sorted by their current position.
		 *
		 * Useful as a starting point before calling reorder() — retrieve the current
		 * order, move items around, then pass the result straight in.
		 *
		 * @return int[] Category IDs in ascending position order.
		 *
		 * @example
		 * ```php
		 * // Get current order, move the last category to the front
		 * $ids = $sharkord->categories->getOrder();
		 * array_unshift($ids, array_pop($ids));
		 * $sharkord->categories->reorder(...$ids);
		 * ```
		 */
		public function getOrder(): array {

			$categories = iterator_to_array($this->cache);

			usort($categories, fn(Category $a, Category $b) => $a->position <=> $b->position);

			return array_map(fn(Category $c) => (int) $c->id, $categories);

		}

		/**
		 * Reorders all categories on the server by providing a full ordered list of IDs.
		 *
		 * The server will push a `categories.onUpdate` event for each affected category.
		 * All cached models are updated automatically via the subscription handler.
		 *
		 * The provided list must contain every existing category ID — omitting an ID
		 * may result in undefined server behaviour.
		 *
		 * Requires the MANAGE_CATEGORIES permission.
		 *
		 * @param int ...$categoryIds The category IDs in the desired display order.
		 * @return PromiseInterface Resolves with true on success, rejects on failure.
		 *
		 * @throws \InvalidArgumentException If no category IDs are provided, if duplicate IDs are present,
		 *                                   or if the number of IDs does not match the number of cached categories.
		 *
		 * @example
		 * ```php
		 * // Move category 7 to the second position
		 * $sharkord->categories->reorder(1, 7, 3, 5)->then(function() {
		 *     echo "Categories reordered.\n";
		 * });
		 * ```
		 */
		public function reorder(int ...$categoryIds): PromiseInterface {

			return $this->guardedAsync(function () use ($categoryIds) {

				if (empty($categoryIds)) {
					throw new \InvalidArgumentException(
						"reorder() requires at least one category ID."
					);
				}

				if (count($categoryIds) !== count(array_unique($categoryIds))) {
					throw new \InvalidArgumentException(
						"reorder() received duplicate category IDs."
					);
				}
				
				if (count($categoryIds) !== count($this->cache)) {
					throw new \InvalidArgumentException(
						"reorder() received " . count($categoryIds) . " category ID(s), but "
							. count($this->cache) . " categories are cached. All categories must be included in the ordering."
					);
				}

				$this->sharkord->guard->requirePermission(Permission::MANAGE_CATEGORIES);

				return $this->sharkord->gateway->sendRpc("mutation", [
					"input" => ["categoryIds" => array_values($categoryIds)],
					"path"  => "categories.reorder",
				])->then(fn(array $r) => PromiseUtils::expectDataResponse($r, 'reorder categories'));

			});

		}

		/**
		 * Fetches fresh data for a category directly from the server.
		 *
		 * The returned model is hydrated into the local cache. Useful when you need
		 * guaranteed up-to-date data for a specific category without waiting for a
		 * subscription event.
		 *
		 * @param int $id The category ID to fetch.
		 * @return PromiseInterface Resolves with the Category model, rejects on failure.
		 *
		 * @example
		 * ```php
		 * $sharkord->categories->fetch(3)->then(function(\Sharkord\Models\Category $category) {
		 *     echo "Fetched: {$category->name} (position {$category->position})\n";
		 * });
		 * ```
		 */
		public function fetch(int $id): PromiseInterface {

			return $this->guardedAsync(function () use ($id) {

				return $this->sharkord->gateway->sendRpc("query", [
					"input" => ["categoryId" => $id],
					"path"  => "categories.get",
				])->then(function (array $response) use ($id) {

					$raw = $response['data']
						?? throw new \RuntimeException(
							"categories.get response missing 'data' for category ID {$id}."
						);

					$this->cache->add($raw);

					return $this->cache->get($id)
						?? throw new \RuntimeException(
							"Category ID {$id} was not found in cache after fetch()."
						);

				});

			});

		}

		/**
		 * Retrieves a cached category by ID or name.
		 *
		 * @param int|string $idOrName The category ID or display name.
		 * @return Category|null The cached Category model, or null if not found.
		 *
		 * @example
		 * ```php
		 * $category = $sharkord->categories->get(3);
		 * $category = $sharkord->categories->get('🎮 Gaming');
		 * ```
		 */
		public function get(int|string $idOrName): ?Category {
 
			return $this->cache->get($idOrName);
 
		}

		/**
		 * Returns the underlying Categories collection.
		 *
		 * @return CategoriesCollection
		 *
		 * @example
		 * ```php
		 * foreach ($sharkord->categories->collection() as $id => $category) {
		 *     echo "{$id}: {$category->name} (position {$category->position})\n";
		 * }
		 * ```
		 */
		public function collection(): CategoriesCollection {

			return $this->cache;

		}

	}

?>
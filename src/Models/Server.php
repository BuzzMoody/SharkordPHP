<?php

	declare(strict_types=1);

	namespace Sharkord\Models;
	
	use Sharkord\Sharkord;

	/**
	 * Class Server
	 *
	 * Represents the server environment and its settings.
	 *
	 * @package Sharkord\Models
	 */
	class Server {

		/**
		 * @var array Stores all dynamic server data from the API
		 */
		private array $attributes = [];

		/**
		 * Server constructor.
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
		 * Factory method to create a Server from raw API data.
		 *
		 * @param array    $raw      The raw server data from the server.
		 * @param Sharkord $sharkord Reference to the main bot instance.
		 * @return self
		 */
		public static function fromArray(array $raw, Sharkord $sharkord): self {
			return new self($sharkord, $raw);
		}
		
		/**
		 * Updates the Server's information dynamically.
		 *
		 * @internal This method is for internal framework use only. Do not call this directly.
		 * @param array $raw The raw Server data.
		 * @return void
		 */
		public function updateFromArray(array $raw): void {
			
			// Merge the new data into our attributes array
			$this->attributes = array_merge($this->attributes, $raw);
			
		}
		
		/**
		 * Returns all the attributes as an array. Perfect for debugging!
		 *
		 * @return array
		 *
		 * @example
		 * ```php
		 * var_dump($sharkord->servers->getFirst()?->toArray());
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
				'id'    => isset($this->attributes['serverId']),
				default => isset($this->attributes[$name]),
			};

		}

		/**
		 * Magic getter. Triggered whenever you try to access a property 
		 * that isn't explicitly defined.
		 *
		 * @param string $name Property name.
		 * @return mixed
		 */
		public function __get(string $name): mixed {
			
			// Create an alias so that $server->id returns the serverId
			if ($name === 'id') {
				return $this->attributes['serverId'] ?? null;
			}
			
			// Otherwise, look for the requested property normally
			return $this->attributes[$name] ?? null;
			
		}

	}
	
?>
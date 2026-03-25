<?php

	declare(strict_types=1);

	namespace Sharkord\Models;
	
	use Sharkord\Sharkord;
	use React\Promise\PromiseInterface;

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
		 * Fetches the full administrative settings for this server.
		 *
		 * Convenience wrapper around {@see \Sharkord\Managers\ServerManager::getSettings()}.
		 * Returns a {@see ServerSettings} model containing privileged fields such as
		 * `secretToken` and `allowNewUsers` that are not included in the public settings.
		 *
		 * Requires the MANAGE_SETTINGS permission.
		 *
		 * @return PromiseInterface Resolves with a {@see ServerSettings} instance, rejects on failure.
		 *
		 * @example
		 * ```php
		 * $server->getSettings()->then(function(\Sharkord\Models\ServerSettings $settings) {
		 *     echo "Server: {$settings->name}\n";
		 *     echo "Allow signup: " . ($settings->allowNewUsers ? 'Yes' : 'No') . "\n";
		 * });
		 * ```
		 */
		public function getSettings(): PromiseInterface {

			return $this->sharkord->servers->getSettings();

		}

		/**
		 * Updates one or more server settings.
		 *
		 * Convenience wrapper that fetches the current {@see ServerSettings} and
		 * delegates to {@see ServerSettings::update()}. The server will broadcast
		 * an `onServerSettingsUpdate` event, which updates the cached Server model
		 * automatically.
		 *
		 * Requires the MANAGE_SETTINGS permission.
		 *
		 * @param string|null $name                   New server display name.
		 * @param string|null $description            New server description.
		 * @param string|null $password               New server password. Pass an empty string to remove.
		 * @param bool|null   $allowNewUsers          Whether to permit new user registrations.
		 * @param bool|null   $directMessagesEnabled  Whether to enable direct messaging server-wide.
		 * @param bool|null   $enablePlugins          Whether to enable server plugins.
		 * @param bool|null   $enableSearch           Whether to enable server-wide search.
		 * @return PromiseInterface Resolves with true on success, rejects on failure.
		 *
		 * @example
		 * ```php
		 * $server->edit(name: 'The Boyz', allowNewUsers: false)->then(function() {
		 *     echo "Settings updated!\n";
		 * });
		 * ```
		 */
		public function edit(
			?string $name = null,
			?string $description = null,
			?string $password = null,
			?bool   $allowNewUsers = null,
			?bool   $directMessagesEnabled = null,
			?bool   $enablePlugins = null,
			?bool   $enableSearch = null,
		): PromiseInterface {

			return $this->getSettings()->then(
				fn(ServerSettings $settings) => $settings->update(
					name: $name,
					description: $description,
					password: $password,
					allowNewUsers: $allowNewUsers,
					directMessagesEnabled: $directMessagesEnabled,
					enablePlugins: $enablePlugins,
					enableSearch: $enableSearch,
				)
			);

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
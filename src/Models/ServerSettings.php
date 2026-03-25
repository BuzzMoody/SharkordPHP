<?php

	declare(strict_types=1);

	namespace Sharkord\Models;

	use React\Promise\PromiseInterface;
	use function React\Promise\resolve;
	use Sharkord\Sharkord;
	use Sharkord\Permission;
	use Sharkord\Internal\GuardedAsync;
	use Sharkord\Internal\PromiseUtils;

	/**
	 * Class ServerSettings
	 *
	 * Represents the full administrative settings for the connected server,
	 * as returned by the `others.getSettings` RPC endpoint.
	 *
	 * Unlike the {@see Server} model — which is hydrated from public settings
	 * pushed to all connected clients — this model includes privileged fields
	 * such as `secretToken` and `allowNewUsers` that are only accessible to
	 * users with the MANAGE_SETTINGS permission.
	 *
	 * Obtain an instance via `$sharkord->servers->getSettings()`. Mutations
	 * made through `update()` will broadcast an `onServerSettingsUpdate` event
	 * to all connected clients, which will in turn update the cached {@see Server}
	 * model automatically.
	 *
	 * @property-read string           $name                            Server display name.
	 * @property-read string           $description                     Server description.
	 * @property-read string|null      $password                        Server password, or null if none is set.
	 * @property-read string           $serverId                        Unique server UUID.
	 * @property-read string|null      $secretToken                     Secret token for server integrations.
	 * @property-read int|null         $logoId                          Attachment ID of the server logo.
	 * @property-read bool             $allowNewUsers                   Whether new user registrations are permitted.
	 * @property-read bool             $directMessagesEnabled           Whether direct messaging is enabled server-wide.
	 * @property-read bool             $storageUploadEnabled            Whether file uploads are enabled.
	 * @property-read int              $storageQuota                    Total server storage quota in bytes.
	 * @property-read int              $storageUploadMaxFileSize        Maximum upload size per file in bytes.
	 * @property-read int              $storageMaxAvatarSize            Maximum avatar file size in bytes.
	 * @property-read int              $storageMaxBannerSize            Maximum banner file size in bytes.
	 * @property-read int              $storageMaxFilesPerMessage       Maximum number of files attachable per message.
	 * @property-read bool             $storageFileSharingInDirectMessages Whether file sharing is allowed in DMs.
	 * @property-read int              $storageSpaceQuotaByUser         Per-user storage quota in bytes, or 0 for unlimited.
	 * @property-read string           $storageOverflowAction           Action taken when storage is full (e.g. 'prevent').
	 * @property-read bool             $enablePlugins                   Whether server plugins are enabled.
	 * @property-read bool             $enableSearch                    Whether server search is enabled.
	 * @property-read int|null         $webRtcMaxBitrate                Maximum WebRTC bitrate in bits per second.
	 * @property-read Attachment|null  $logo                            The server logo as an Attachment, or null.
	 *
	 * @package Sharkord\Models
	 *
	 * @example
	 * ```php
	 * $sharkord->servers->getSettings()->then(function (\Sharkord\Models\ServerSettings $settings) {
	 *     echo "Server: {$settings->name}\n";
	 *     echo "New users allowed: " . ($settings->allowNewUsers ? 'Yes' : 'No') . "\n";
	 *     echo "Storage quota: " . number_format($settings->storageQuota / 1073741824, 1) . " GB\n";
	 * });
	 * ```
	 */
	class ServerSettings {

		use GuardedAsync;

		/**
		 * @var array<string, mixed> Raw settings data from the API.
		 */
		private array $attributes = [];

		/**
		 * ServerSettings constructor.
		 *
		 * @param Sharkord             $sharkord Reference to the main bot instance.
		 * @param array<string, mixed> $rawData  The raw settings payload from the API.
		 */
		public function __construct(
			private readonly Sharkord $sharkord,
			array $rawData
		) {
			$this->updateFromArray($rawData);
		}

		/**
		 * Factory method to create a ServerSettings instance from a raw API data array.
		 *
		 * @param array<string, mixed> $raw      The raw settings payload.
		 * @param Sharkord             $sharkord Reference to the main bot instance.
		 * @return self
		 */
		public static function fromArray(array $raw, Sharkord $sharkord): self {
			return new self($sharkord, $raw);
		}

		/**
		 * Merges new data into the settings model in place.
		 *
		 * Nested `logo` arrays are automatically converted to {@see Attachment} instances.
		 * Safe to call with partial payloads — only provided keys are updated.
		 *
		 * @internal
		 * @param array<string, mixed> $raw Raw settings data, may be a partial payload.
		 * @return void
		 */
		public function updateFromArray(array $raw): void {

			if (isset($raw['logo']) && is_array($raw['logo'])) {
				$raw['logo'] = Attachment::fromArray($raw['logo']);
			}

			$this->attributes = array_merge($this->attributes, $raw);

		}

		/**
		 * Updates one or more server settings via the `others.updateSettings` RPC.
		 *
		 * Only fields explicitly passed (i.e. not null) are included in the mutation
		 * payload. After a successful update the server will broadcast an
		 * `onServerSettingsUpdate` event, which the framework will use to refresh the
		 * cached {@see Server} model automatically.
		 *
		 * Requires the MANAGE_SETTINGS permission.
		 *
		 * @param string|null $name                   New server display name.
		 * @param string|null $description            New server description.
		 * @param string|null $password               New server password. Pass an empty string to remove an existing password.
		 * @param bool|null   $allowNewUsers          Whether to permit new user registrations.
		 * @param bool|null   $directMessagesEnabled  Whether to enable direct messaging server-wide.
		 * @param bool|null   $enablePlugins          Whether to enable server plugins.
		 * @param bool|null   $enableSearch           Whether to enable server-wide search.
		 * @return PromiseInterface Resolves with true on success, rejects on failure.
		 *
		 * @example
		 * ```php
		 * $sharkord->servers->getSettings()->then(function (\Sharkord\Models\ServerSettings $settings) {
		 *     return $settings->update(
		 *         name: 'The Boyz',
		 *         allowNewUsers: false,
		 *         directMessagesEnabled: true,
		 *         enableSearch: true,
		 *     );
		 * })->then(function () {
		 *     echo "Settings updated!\n";
		 * });
		 * ```
		 */
		public function update(
			?string $name = null,
			?string $description = null,
			?string $password = null,
			?bool   $allowNewUsers = null,
			?bool   $directMessagesEnabled = null,
			?bool   $enablePlugins = null,
			?bool   $enableSearch = null,
		): PromiseInterface {

			return $this->guardedAsync(function () use (
				$name, $description, $password,
				$allowNewUsers, $directMessagesEnabled,
				$enablePlugins, $enableSearch
			) {

				$this->sharkord->guard->requirePermission(Permission::MANAGE_SETTINGS);

				$input = array_filter([
					'name'                  => $name,
					'description'           => $description,
					'password'              => $password,
					'allowNewUsers'         => $allowNewUsers,
					'directMessagesEnabled' => $directMessagesEnabled,
					'enablePlugins'         => $enablePlugins,
					'enableSearch'          => $enableSearch,
				], fn(mixed $v): bool => $v !== null);

				if (empty($input)) {
					return resolve(true);
				}

				return $this->sharkord->gateway->sendRpc("mutation", [
					"input" => $input,
					"path"  => "others.updateSettings",
				])->then(function (array $response) use ($input): bool {

					PromiseUtils::expectDataResponse($response, 'update server settings');
					$this->updateFromArray($response['data'] ?? $input);
					return true;

				});

			});

		}

		/**
		 * Returns the settings data as a plain array.
		 *
		 * @return array<string, mixed>
		 */
		public function toArray(): array {
			return $this->attributes;
		}

		/**
		 * Magic getter for settings properties.
		 *
		 * @param string $name Property name.
		 * @return mixed
		 */
		public function __get(string $name): mixed {
			return $this->attributes[$name] ?? null;
		}

		/**
		 * Magic isset check.
		 *
		 * @param string $name Property name.
		 * @return bool
		 */
		public function __isset(string $name): bool {
			return isset($this->attributes[$name]);
		}

	}

?>
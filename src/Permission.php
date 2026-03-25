<?php

	declare(strict_types=1);

	namespace Sharkord;

	/**
	 * Enum Permission
	 *
	 * Represents the server-wide permissions that can be assigned to roles.
	 *
	 * @package Sharkord
	 *
	 * @example
	 * ```php
	 * // Check if the bot has a specific permission
	 * if ($sharkord->bot->hasPermission(\Sharkord\Permission::MANAGE_MESSAGES)) {
	 *     echo "Bot can manage messages.\n";
	 * }
	 *
	 * // Grant permissions when editing a role
	 * $sharkord->roles->get(3)?->edit(
	 *     'Moderators',
	 *     '#00aaff',
	 *     \Sharkord\Permission::MANAGE_MESSAGES,
	 *     \Sharkord\Permission::MANAGE_USERS,
	 * );
	 * ```
	 */
	enum Permission: string {
		
		case SEND_MESSAGES = 'SEND_MESSAGES';
		case REACT_TO_MESSAGES = 'REACT_TO_MESSAGES';
		case UPLOAD_FILES = 'UPLOAD_FILES';
		case JOIN_VOICE_CHANNELS = 'JOIN_VOICE_CHANNELS';
		case SHARE_SCREEN = 'SHARE_SCREEN';
		case ENABLE_WEBCAM = 'ENABLE_WEBCAM';
		case MANAGE_CHANNELS = 'MANAGE_CHANNELS';
		case MANAGE_CHANNEL_PERMISSIONS = 'MANAGE_CHANNEL_PERMISSIONS';
		case MANAGE_CATEGORIES = 'MANAGE_CATEGORIES';
		case MANAGE_ROLES = 'MANAGE_ROLES';
		case MANAGE_EMOJIS = 'MANAGE_EMOJIS';
		case MANAGE_SETTINGS = 'MANAGE_SETTINGS';
		case MANAGE_USERS = 'MANAGE_USERS';
		case MANAGE_MESSAGES = 'MANAGE_MESSAGES';
		case MANAGE_STORAGE = 'MANAGE_STORAGE';
		case MANAGE_INVITES = 'MANAGE_INVITES';
		case MANAGE_UPDATES = 'MANAGE_UPDATES';
		case MANAGE_PLUGINS = 'MANAGE_PLUGINS';
		case EXECUTE_PLUGIN_COMMANDS = 'EXECUTE_PLUGIN_COMMANDS';
		case VIEW_USER_SENSITIVE_DATA = 'VIEW_USER_SENSITIVE_DATA';
		
	}

?>
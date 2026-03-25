<?php

	namespace Sharkord\Commands;

	use Sharkord\Sharkord;
	use Sharkord\Models\Message;

	/**
	 * Interface CommandInterface
	 *
	 * Defines the contract that all bot commands must follow.
	 *
	 * @package Sharkord\Commands
	 */
	interface CommandInterface {

		/**
		 * Retrieves the unique name of the command.
		 *
		 * This name is used to invoke the command (e.g., "ping" for "!ping").
		 *
		 * @return string The command name.
		 *
		 * @example
		 * ```php
		 * public function getName(): string {
		 *     return 'ping';
		 * }
		 * ```
		 */
		public function getName(): string;

		/**
		 * Retrieves a brief description of what the command does.
		 *
		 * @return string The command description.
		 *
		 * @example
		 * ```php
		 * public function getDescription(): string {
		 *     return 'Replies with Pong!';
		 * }
		 * ```
		 */
		public function getDescription(): string;
		
		/**
		 * A regex pattern match that will trigger the command
		 *
		 * @return string The command patttern.
		 *
		 * @example
		 * ```php
		 * public function getPattern(): string {
		 *     return '/^ping$/i';
		 * }
		 * ```
		 */
		public function getPattern(): string;

		/**
		 * Handles the execution of the command.
		 *
		 * @param Sharkord $sharkord     The main bot instance.
		 * @param Message  $message The message that triggered the command.
		 * @param string   $args    The arguments passed with the command.
		 * @param array    $matches Regex capture groups from the command pattern.
		 * @return void
		 *
		 * @example
		 * ```php
		 * public function handle(Sharkord $sharkord, Message $message, string $args, array $matches): void {
		 *     $message->reply('Pong!');
		 * }
		 * ```
		 */
		public function handle(Sharkord $sharkord, Message $message, string $args, array $matches): void;

	}
	
?>
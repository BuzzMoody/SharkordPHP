<?php

	declare(strict_types=1);

	namespace Sharkord\Commands;

	use Sharkord\Sharkord;
	use Sharkord\Models\Message;

	/**
	 * Class CommandRouter
	 *
	 * Responsible for loading, registering, and executing chat commands.
	 *
	 * @package Sharkord\Commands
	 */
	class CommandRouter {

		/**
		 * @var array<string, CommandInterface> Registry of available commands.
		 */
		private array $commands = [];

		/**
		 * CommandRouter constructor.
		 *
		 * @param Sharkord $sharkord The main bot instance.
		 */
		public function __construct(
			private Sharkord $sharkord
		) {}

		/**
		 * Registers a single command instance to the router.
		 *
		 * @param CommandInterface $command The command object to register.
		 * @return void
		 *
		 * @example
		 * ```php
		 * $sharkord->commands->register(new PingCommand());
		 * ```
		 */
		public function register(CommandInterface $command): void {
			
			$this->commands[$command->getName()] = $command;
			$this->sharkord->logger->debug("Registered command: " . $command->getName());
			
		}

		/**
		 * Automatically loads and registers all command classes from a specific directory.
		 *
		 * @param string $directory The absolute path to the directory containing command classes.
		 * @param string $namespace (Optional) The namespace used in the command files. Default is empty (global).
		 * @return void
		 *
		 * @example
		 * ```php
		 * $sharkord->commands->loadFromDirectory(__DIR__ . '/Commands', 'App\\Commands');
		 * ```
		 */
		public function loadFromDirectory(string $directory, string $namespace = ''): void {
			
			if (!is_dir($directory)) {
				$this->sharkord->logger->warning("Command directory does not exist: {$directory}");
				return;
			}
			
			$namespace = rtrim($namespace, '\\');

			$files = glob($directory . '/*.php');
			
			if ($files === false) {
				$this->sharkord->logger->warning("Failed to scan command directory: {$directory}");
				return;
			}

			foreach ($files as $file) {

				if (!is_file($file) || !is_readable($file)) {
					$this->sharkord->logger->warning("Skipping unreadable command file: {$file}");
					continue;
				}

				require_once $file;
				$className = basename($file, '.php');
				$fullClassName = $namespace ? $namespace . '\\' . $className : $className;

				try {
					if (class_exists($fullClassName)) {
						$reflection = new \ReflectionClass($fullClassName);
						
						if ($reflection->implementsInterface(CommandInterface::class) && !$reflection->isAbstract()) {
							$this->register(new $fullClassName());
						}
					}
				} catch (\Throwable $e) {
					$this->sharkord->logger->error("Failed to load command class '{$fullClassName}': " . $e->getMessage());
				}
				
			}
			
		}

		/**
		 * Checks if a received message matches a command pattern and executes it.
		 *
		 * @param Message	$message	The received message object.
		 * @param array		$matches	The original regex matches
		 * @return void
		 *
		 * @example
		 * ```php
		 * // Typically called automatically by the framework:
		 * if (preg_match('/^!(\w+)\s*(.*)/s', $message->content, $matches)) {
		 *     $sharkord->commands->handle($message, $matches);
		 * }
		 * ```
		 */
		public function handle(Message $message, array $matches): void {

			$commandName = strtolower($matches[1]);
			$args = $matches[2] ?? '';
			
			foreach ($this->commands as $command) {
				if (preg_match($command->getPattern(), $commandName, $cmdMatches)) {
					
					$this->sharkord->logger->debug("Matched command: $commandName");
					
					try {
						$command->handle($this->sharkord, $message, $args, $cmdMatches);
					} catch (\Throwable $e) {
						$this->sharkord->logger->error("Error executing command '{$commandName}': " . $e->getMessage());
					}
					
					return; // Stop processing once a match is found
				}
			}
			
		}

		/**
		 * Retrieves all registered commands. Useful for generating "Help" menus.
		 *
		 * @return array<string, CommandInterface>
		 *
		 * @example
		 * ```php
		 * foreach ($sharkord->commands->getCommands() as $name => $command) {
		 *     echo "!{$name} — {$command->getDescription()}\n";
		 * }
		 * ```
		 */
		public function getCommands(): array {
			return $this->commands;
		}

	}

?>
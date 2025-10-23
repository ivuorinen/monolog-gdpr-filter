<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Exceptions;

use Throwable;

/**
 * Exception thrown when Laravel service registration fails.
 *
 * This exception is thrown when:
 * - Service provider fails to register GDPR processor
 * - Configuration publishing fails
 * - Logging channel registration fails
 * - Artisan command registration fails
 * - Service binding or resolution fails
 *
 * @api
 */
class ServiceRegistrationException extends GdprProcessorException
{
    /**
     * Create an exception for channel registration failure.
     *
     * @param string $channelName The channel that failed to register
     * @param string $reason The reason for failure
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function forChannel(
        string $channelName,
        string $reason,
        ?Throwable $previous = null
    ): static {
        $message = sprintf("Failed to register GDPR processor with channel '%s': %s", $channelName, $reason);

        return self::withContext($message, [
            'channel_name' => $channelName,
            'reason' => $reason,
            'category' => 'channel_registration',
        ], 0, $previous);
    }

    /**
     * Create an exception for service binding failure.
     *
     * @param string $serviceName The service that failed to bind
     * @param string $reason The reason for failure
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function forServiceBinding(
        string $serviceName,
        string $reason,
        ?Throwable $previous = null
    ): static {
        $message = sprintf("Failed to bind service '%s': %s", $serviceName, $reason);

        return self::withContext($message, [
            'service_name' => $serviceName,
            'reason' => $reason,
            'category' => 'service_binding',
        ], 0, $previous);
    }

    /**
     * Create an exception for configuration publishing failure.
     *
     * @param string $configPath The configuration path that failed
     * @param string $reason The reason for failure
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function forConfigPublishing(
        string $configPath,
        string $reason,
        ?Throwable $previous = null
    ): static {
        $message = sprintf("Failed to publish configuration to '%s': %s", $configPath, $reason);

        return self::withContext($message, [
            'config_path' => $configPath,
            'reason' => $reason,
            'category' => 'config_publishing',
        ], 0, $previous);
    }

    /**
     * Create an exception for command registration failure.
     *
     * @param string $commandClass The command class that failed to register
     * @param string $reason The reason for failure
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function forCommandRegistration(
        string $commandClass,
        string $reason,
        ?Throwable $previous = null
    ): static {
        $message = sprintf("Failed to register command '%s': %s", $commandClass, $reason);

        return self::withContext($message, [
            'command_class' => $commandClass,
            'reason' => $reason,
            'category' => 'command_registration',
        ], 0, $previous);
    }
}

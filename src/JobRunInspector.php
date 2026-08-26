<?php

namespace Laravel\Horizon;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Str;
use Throwable;

/**
 * Read the cancellation group a queued job belongs to.
 *
 * A group ties the jobs of one logical run together — a self-chained walk, or
 * a fan-out of related jobs — so an operator can stop all of them at once. The
 * group lives on the job instance, so reading it means unserializing the
 * command. Nothing here throws: an unreadable payload simply has no group.
 */
class JobRunInspector
{
    /**
     * Create a new job run inspector instance.
     *
     * @return void
     */
    public function __construct(protected Container $container)
    {
    }

    /**
     * Get the cancellation group of the job contained in the given payload.
     *
     * @param  array<string, mixed>  $payload
     * @return string|null
     */
    public function groupForPayload(array $payload)
    {
        // The class name is plain JSON, so it can be checked without touching
        // the serialized command. Only a class that opted into run
        // cancellation is ever unserialized, which keeps payloads for
        // unrelated jobs out of this process entirely.
        if (! $this->declaresGroup($payload['data']['commandName'] ?? null)) {
            return null;
        }

        $command = $this->unserializeCommand($payload);

        return is_null($command) ? null : $this->groupForCommand($command);
    }

    /**
     * Determine if the named class opts into run cancellation.
     *
     * @param  mixed  $class
     * @return bool
     */
    protected function declaresGroup($class)
    {
        if (! is_string($class) || $class === '') {
            return false;
        }

        try {
            return class_exists($class) && method_exists($class, 'cancellationGroup');
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Get the cancellation group declared by the given command.
     *
     * @param  object  $command
     * @return string|null
     */
    public function groupForCommand($command)
    {
        if (! method_exists($command, 'cancellationGroup')) {
            return null;
        }

        try {
            $group = $command->cancellationGroup();
        } catch (Throwable $e) {
            return null;
        }

        return $this->normalize($group);
    }

    /**
     * Normalize a declared group into a key that may be stored and addressed.
     *
     * @param  mixed  $group
     * @return string|null
     */
    public function normalize($group)
    {
        if (! is_string($group)) {
            return null;
        }

        $group = trim($group);

        return $this->valid($group) ? $group : null;
    }

    /**
     * Determine if the given group is addressable.
     *
     * @param  string  $group
     * @return bool
     */
    public function valid($group)
    {
        return (bool) preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $group);
    }

    /**
     * Unserialize the command contained in the given payload.
     *
     * @param  array<string, mixed>  $payload
     * @return object|null
     */
    protected function unserializeCommand(array $payload)
    {
        $command = $payload['data']['command'] ?? null;

        if (! is_string($command) || $command === '') {
            return null;
        }

        try {
            if (! Str::startsWith($command, 'O:')) {
                if (! $this->container->bound(Encrypter::class)) {
                    return null;
                }

                $command = $this->container->make(Encrypter::class)->decrypt($command);
            }

            $unserialized = @unserialize($command);
        } catch (Throwable $e) {
            return null;
        }

        return is_object($unserialized) ? $unserialized : null;
    }
}

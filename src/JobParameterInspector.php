<?php

namespace Laravel\Horizon;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Str;
use Laravel\Horizon\Exceptions\InvalidJobParameterException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use Throwable;

class JobParameterInspector
{
    /**
     * Create a new job parameter inspector instance.
     *
     * @param  \Illuminate\Contracts\Container\Container  $container
     * @return void
     */
    public function __construct(protected Container $container)
    {
    }

    /**
     * Describe the constructor parameters of the job contained in the given payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array{class: string|null, editable: bool, reason: string|null, parameters: array<int, array<string, mixed>>}
     */
    public function inspect(array $payload)
    {
        $class = $this->commandName($payload);

        if ($reason = $this->unsupportedReason($class)) {
            return $this->unsupported($class, $reason);
        }

        try {
            [$command] = $this->unserializeCommand($payload);
        } catch (Throwable $e) {
            return $this->unsupported($class, 'The job could not be unserialized: '.$e->getMessage());
        }

        return $this->describe($class, $command);
    }

    /**
     * Apply the given parameter overrides to the job contained in the payload.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     *
     * @throws \Laravel\Horizon\Exceptions\InvalidJobParameterException
     */
    public function applyOverrides(array $payload, array $overrides)
    {
        if (empty($overrides)) {
            return $payload;
        }

        $class = $this->commandName($payload);

        if ($reason = $this->unsupportedReason($class)) {
            throw new InvalidJobParameterException($reason);
        }

        try {
            [$command, $encrypted] = $this->unserializeCommand($payload);
        } catch (Throwable $e) {
            throw new InvalidJobParameterException('The job could not be unserialized: '.$e->getMessage());
        }

        $parameters = collect($this->describe($class, $command)['parameters'])->keyBy('name');

        foreach ($overrides as $name => $value) {
            $parameter = $parameters->get($name);

            if (is_null($parameter)) {
                throw new InvalidJobParameterException("The job does not accept a [{$name}] parameter.");
            }

            if (! $parameter['editable']) {
                throw new InvalidJobParameterException("The [{$name}] parameter may not be edited. {$parameter['reason']}");
            }

            $this->writeProperty($command, $name, $this->castValue($name, $value, $parameter));
        }

        $payload['data']['command'] = $this->serializeCommand($command, $encrypted);

        return $payload;
    }

    /**
     * Get the command name from the given payload.
     *
     * @param  array<string, mixed>  $payload
     * @return string|null
     */
    protected function commandName(array $payload)
    {
        $class = $payload['data']['commandName'] ?? null;

        return is_string($class) && $class !== '' ? $class : null;
    }

    /**
     * Get the reason the given job class does not support parameter editing, if any.
     *
     * @param  string|null  $class
     * @return string|null
     */
    protected function unsupportedReason($class)
    {
        if (is_null($class)) {
            return 'This job does not contain a serialized command.';
        }

        if (! class_exists($class)) {
            return "The [{$class}] class is not available in this application.";
        }

        if (is_a($class, CallQueuedClosure::class, true)) {
            return 'Queued closures do not expose constructor parameters.';
        }

        return null;
    }

    /**
     * Describe the constructor parameters of the given command.
     *
     * @param  string  $class
     * @param  object  $command
     * @return array{class: string, editable: bool, reason: string|null, parameters: array<int, array<string, mixed>>}
     */
    protected function describe($class, $command)
    {
        $constructor = (new ReflectionClass($class))->getConstructor();

        if (is_null($constructor) || $constructor->getNumberOfParameters() === 0) {
            return $this->unsupported($class, 'This job does not accept any constructor parameters.');
        }

        $properties = $this->propertyValues($command);

        $parameters = array_map(function ($parameter) use ($properties) {
            return $this->describeParameter($parameter, $properties);
        }, $constructor->getParameters());

        $editable = collect($parameters)->contains('editable', true);

        return [
            'class' => $class,
            'editable' => $editable,
            'reason' => $editable ? null : 'None of this job\'s parameters may be edited.',
            'parameters' => $parameters,
        ];
    }

    /**
     * Describe a single constructor parameter.
     *
     * @param  \ReflectionParameter  $parameter
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    protected function describeParameter($parameter, array $properties)
    {
        $name = $parameter->getName();
        $type = $this->describeType($parameter);

        $described = [
            'name' => $name,
            'type' => $type,
            'nullable' => ! $parameter->hasType() || $parameter->getType()->allowsNull(),
            'value' => null,
            'default' => $this->defaultValue($parameter),
            'preview' => null,
            'editable' => false,
            'reason' => null,
        ];

        if (! in_array($type, $this->editableTypes())) {
            return array_merge($described, [
                'preview' => $this->previewValue($properties[$name] ?? null),
                'reason' => 'Only scalar and array parameters may be edited.',
            ]);
        }

        if (! array_key_exists($name, $properties)) {
            return array_merge($described, [
                'reason' => 'The job does not store this parameter on a matching property.',
            ]);
        }

        $value = $properties[$name];

        if (! $this->isEditableValue($value)) {
            return array_merge($described, [
                'preview' => $this->previewValue($value),
                'reason' => 'Only scalar values and plain arrays may be edited.',
            ]);
        }

        return array_merge($described, [
            'value' => $value,
            'editable' => true,
        ]);
    }

    /**
     * Get the declared default of the given constructor parameter, if any.
     *
     * @param  \ReflectionParameter  $parameter
     * @return mixed
     */
    protected function defaultValue($parameter)
    {
        if (! $parameter->isDefaultValueAvailable()) {
            return null;
        }

        $default = $parameter->getDefaultValue();

        return $this->isEditableValue($default) ? $default : null;
    }

    /**
     * Get a readable type for the given constructor parameter.
     *
     * @param  \ReflectionParameter  $parameter
     * @return string
     */
    protected function describeType($parameter)
    {
        $type = $parameter->getType();

        if (is_null($type)) {
            return 'mixed';
        }

        return $type instanceof ReflectionNamedType
            ? $type->getName()
            : (string) $type;
    }

    /**
     * Get the parameter types that may be edited from the dashboard.
     *
     * @return array<int, string>
     */
    protected function editableTypes()
    {
        return ['mixed', 'int', 'float', 'bool', 'string', 'array', 'iterable'];
    }

    /**
     * Get the current property values of the given command.
     *
     * @param  object  $command
     * @return array<string, mixed>
     */
    protected function propertyValues($command)
    {
        $values = [];

        for ($class = new ReflectionClass($command); $class; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                if ($property->isStatic() || array_key_exists($property->getName(), $values)) {
                    continue;
                }

                $property->setAccessible(true);

                if ($property->isInitialized($command)) {
                    $values[$property->getName()] = $property->getValue($command);
                }
            }
        }

        return $values;
    }

    /**
     * Write the given value to the command's matching property.
     *
     * @param  object  $command
     * @param  string  $name
     * @param  mixed  $value
     * @return void
     *
     * @throws \Laravel\Horizon\Exceptions\InvalidJobParameterException
     */
    protected function writeProperty($command, $name, $value)
    {
        for ($class = new ReflectionClass($command); $class; $class = $class->getParentClass()) {
            if (! $class->hasProperty($name)) {
                continue;
            }

            $property = $class->getProperty($name);

            if ($property->isStatic()) {
                continue;
            }

            $property->setAccessible(true);

            try {
                $property->setValue($command, $value);
            } catch (Throwable $e) {
                throw new InvalidJobParameterException("The [{$name}] parameter could not be updated: ".$e->getMessage());
            }

            return;
        }

        throw new InvalidJobParameterException("The job does not store a [{$name}] property.");
    }

    /**
     * Determine if the given value may be edited from the dashboard.
     *
     * @param  mixed  $value
     * @return bool
     */
    protected function isEditableValue($value)
    {
        if (is_null($value) || is_scalar($value)) {
            return true;
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (! $this->isEditableValue($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get a readable preview for a value that may not be edited.
     *
     * @param  mixed  $value
     * @return string
     */
    protected function previewValue($value)
    {
        if ($value instanceof Model) {
            return get_class($value).':'.$value->getKey();
        }

        if (is_object($value)) {
            return get_class($value);
        }

        return gettype($value);
    }

    /**
     * Cast the submitted value to the type expected by the job.
     *
     * @param  string  $name
     * @param  mixed  $value
     * @param  array<string, mixed>  $parameter
     * @return mixed
     *
     * @throws \Laravel\Horizon\Exceptions\InvalidJobParameterException
     */
    protected function castValue($name, $value, array $parameter)
    {
        if (! $this->isEditableValue($value)) {
            throw new InvalidJobParameterException("The [{$name}] parameter only accepts scalar values and plain arrays.");
        }

        if (is_null($value)) {
            if ($parameter['nullable'] || is_null($parameter['value'])) {
                return null;
            }

            throw new InvalidJobParameterException("The [{$name}] parameter may not be null.");
        }

        $type = $parameter['type'] === 'mixed'
            ? $this->inferredType($parameter['value'])
            : $parameter['type'];

        return match ($type) {
            'int' => $this->castNumeric($name, $value, 'int'),
            'float' => $this->castNumeric($name, $value, 'float'),
            'bool' => $this->castBoolean($name, $value),
            'string' => $this->castString($name, $value),
            'array', 'iterable' => $this->castArray($name, $value),
            default => $value,
        };
    }

    /**
     * Infer the parameter type from its current value.
     *
     * @param  mixed  $value
     * @return string
     */
    protected function inferredType($value)
    {
        return match (true) {
            is_bool($value) => 'bool',
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_string($value) => 'string',
            is_array($value) => 'array',
            default => 'mixed',
        };
    }

    /**
     * Cast the given value to an integer or float.
     *
     * @param  string  $name
     * @param  mixed  $value
     * @param  string  $type
     * @return int|float
     *
     * @throws \Laravel\Horizon\Exceptions\InvalidJobParameterException
     */
    protected function castNumeric($name, $value, $type)
    {
        if (is_bool($value) || ! is_numeric($value)) {
            throw new InvalidJobParameterException("The [{$name}] parameter must be numeric.");
        }

        if ($type === 'int' && (string) (int) $value !== (string) $value) {
            throw new InvalidJobParameterException("The [{$name}] parameter must be an integer.");
        }

        return $type === 'int' ? (int) $value : (float) $value;
    }

    /**
     * Cast the given value to a boolean.
     *
     * @param  string  $name
     * @param  mixed  $value
     * @return bool
     *
     * @throws \Laravel\Horizon\Exceptions\InvalidJobParameterException
     */
    protected function castBoolean($name, $value)
    {
        $casted = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if (is_null($casted)) {
            throw new InvalidJobParameterException("The [{$name}] parameter must be a boolean.");
        }

        return $casted;
    }

    /**
     * Cast the given value to a string.
     *
     * @param  string  $name
     * @param  mixed  $value
     * @return string
     *
     * @throws \Laravel\Horizon\Exceptions\InvalidJobParameterException
     */
    protected function castString($name, $value)
    {
        if (is_array($value)) {
            throw new InvalidJobParameterException("The [{$name}] parameter must be a string.");
        }

        return is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    }

    /**
     * Cast the given value to an array.
     *
     * @param  string  $name
     * @param  mixed  $value
     * @return array<mixed>
     *
     * @throws \Laravel\Horizon\Exceptions\InvalidJobParameterException
     */
    protected function castArray($name, $value)
    {
        if (! is_array($value)) {
            throw new InvalidJobParameterException("The [{$name}] parameter must be an array.");
        }

        return $value;
    }

    /**
     * Unserialize the command contained in the given payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: object, 1: bool}
     */
    protected function unserializeCommand(array $payload)
    {
        $command = $payload['data']['command'] ?? '';

        if (Str::startsWith($command, 'O:')) {
            return [$this->unserialize($command), false];
        }

        return [$this->unserialize($this->encrypter()->decrypt($command)), true];
    }

    /**
     * Serialize the given command back into its payload representation.
     *
     * @param  object  $command
     * @param  bool  $encrypted
     * @return string
     */
    protected function serializeCommand($command, $encrypted)
    {
        $serialized = serialize($command);

        return $encrypted ? $this->encrypter()->encrypt($serialized) : $serialized;
    }

    /**
     * Unserialize the given command string.
     *
     * @param  string  $command
     * @return object
     *
     * @throws \Laravel\Horizon\Exceptions\InvalidJobParameterException
     */
    protected function unserialize($command)
    {
        $unserialized = @unserialize($command);

        if (! is_object($unserialized)) {
            throw new InvalidJobParameterException('The serialized command could not be read.');
        }

        return $unserialized;
    }

    /**
     * Get the encrypter implementation.
     *
     * @return \Illuminate\Contracts\Encryption\Encrypter
     *
     * @throws \Laravel\Horizon\Exceptions\InvalidJobParameterException
     */
    protected function encrypter()
    {
        if (! $this->container->bound(Encrypter::class)) {
            throw new InvalidJobParameterException('The job payload is encrypted but no encrypter is available.');
        }

        return $this->container->make(Encrypter::class);
    }

    /**
     * Build a description for a job whose parameters may not be edited.
     *
     * @param  string|null  $class
     * @param  string  $reason
     * @return array{class: string|null, editable: bool, reason: string, parameters: array<int, array<string, mixed>>}
     */
    protected function unsupported($class, $reason)
    {
        return [
            'class' => $class,
            'editable' => false,
            'reason' => $reason,
            'parameters' => [],
        ];
    }
}

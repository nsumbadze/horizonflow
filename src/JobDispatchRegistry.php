<?php

namespace Laravel\Horizon;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Throwable;

/**
 * Resolve the job classes an operator may dispatch from the dashboard.
 *
 * Discovery walks the configured paths for classes implementing ShouldQueue.
 * The resulting set is the whole dispatchable surface: a class that is neither
 * discovered nor named in the allow list may not be dispatched, so a request
 * can never reach an arbitrary queueable class inside the framework or a
 * third-party package.
 */
class JobDispatchRegistry
{
    /**
     * The memoized dispatchable job classes.
     *
     * @var array<int, array<string, mixed>>|null
     */
    protected $dispatchable = null;

    /**
     * Create a new job dispatch registry instance.
     *
     * @return void
     */
    public function __construct(protected Container $container, protected Config $config)
    {
    }

    /**
     * Determine if dispatching jobs from the dashboard is enabled.
     *
     * @return bool
     */
    public function enabled()
    {
        return (bool) $this->config->get('horizonxflow.dispatch.enabled', true);
    }

    /**
     * Get the job classes that may be dispatched from the dashboard.
     *
     * @return array<int, array<string, mixed>>
     */
    public function dispatchable()
    {
        if (! is_null($this->dispatchable)) {
            return $this->dispatchable;
        }

        if (! $this->enabled()) {
            return $this->dispatchable = [];
        }

        $classes = $this->filtered($this->discovered());

        return $this->dispatchable = array_values(array_map(
            fn ($class) => $this->describe($class),
            $classes
        ));
    }

    /**
     * Determine if the given class may be dispatched from the dashboard.
     *
     * @param  string  $class
     * @return bool
     */
    public function allows($class)
    {
        return collect($this->dispatchable())->contains('class', $class);
    }

    /**
     * Ensure the given class may be dispatched from the dashboard.
     *
     * @param  string  $class
     * @return string
     *
     * @throws \InvalidArgumentException
     */
    public function ensureDispatchable($class)
    {
        if (! $this->enabled()) {
            throw new InvalidArgumentException('Dispatching jobs from the dashboard is disabled.');
        }

        if ($class === '') {
            throw new InvalidArgumentException('A job class is required.');
        }

        if (! $this->allows($class)) {
            throw new InvalidArgumentException("The [{$class}] job may not be dispatched from the dashboard.");
        }

        return $class;
    }

    /**
     * Describe a dispatchable job class for the dashboard.
     *
     * @param  string  $class
     * @return array<string, mixed>
     */
    protected function describe($class)
    {
        return [
            'class' => $class,
            'name' => class_basename($class),
            'namespace' => Str::beforeLast($class, '\\'),
            'connection' => $this->declaredDefault($class, 'connection'),
            'queue' => $this->declaredDefault($class, 'queue'),
        ];
    }

    /**
     * Get the default value the job class declares for the given property.
     *
     * @param  string  $class
     * @param  string  $property
     * @return string|null
     */
    protected function declaredDefault($class, $property)
    {
        $defaults = (new ReflectionClass($class))->getDefaultProperties();

        $value = $defaults[$property] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Apply the configured allow and deny lists to the given classes.
     *
     * @param  array<int, string>  $classes
     * @return array<int, string>
     */
    protected function filtered(array $classes)
    {
        $allowed = $this->patterns('allowed');
        $denied = $this->patterns('denied');

        if ($allowed !== []) {
            $classes = array_merge($classes, array_filter(
                $allowed,
                fn ($pattern) => ! Str::contains($pattern, '*') && $this->isDispatchable($pattern)
            ));
        }

        $classes = array_filter(array_unique($classes), function ($class) use ($allowed, $denied) {
            if ($this->matches($class, $denied)) {
                return false;
            }

            return $allowed === [] || $this->matches($class, $allowed);
        });

        sort($classes);

        return $classes;
    }

    /**
     * Get the configured patterns for the given dispatch list.
     *
     * @param  string  $key
     * @return array<int, string>
     */
    protected function patterns($key)
    {
        $patterns = $this->config->get("horizonxflow.dispatch.{$key}", []);

        if (! is_array($patterns)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($pattern) => is_string($pattern) ? ltrim(trim($pattern), '\\') : '',
            $patterns
        )));
    }

    /**
     * Determine if the given class matches one of the given patterns.
     *
     * @param  string  $class
     * @param  array<int, string>  $patterns
     * @return bool
     */
    protected function matches($class, array $patterns)
    {
        return $patterns !== [] && Str::is($patterns, $class);
    }

    /**
     * Discover the queueable job classes within the configured paths.
     *
     * @return array<int, string>
     */
    protected function discovered()
    {
        if (! $this->config->get('horizonxflow.dispatch.discover', true)) {
            return [];
        }

        $classes = [];

        foreach ($this->paths() as $path) {
            foreach ($this->phpFiles($path) as $file) {
                $class = $this->classFromFile($file);

                if (! is_null($class) && $this->isDispatchable($class)) {
                    $classes[] = $class;
                }
            }
        }

        return $classes;
    }

    /**
     * Get the paths that are walked when discovering job classes.
     *
     * @return array<int, string>
     */
    protected function paths()
    {
        $configured = $this->config->get('horizonxflow.dispatch.paths', []);

        $paths = is_array($configured) ? array_filter($configured, 'is_string') : [];

        if ($paths === []) {
            $paths = $this->defaultPaths();
        }

        return array_values(array_filter($paths, 'is_dir'));
    }

    /**
     * Get the paths walked when none are configured.
     *
     * @return array<int, string>
     */
    protected function defaultPaths()
    {
        return method_exists($this->container, 'path')
            ? [$this->container->path('Jobs')]
            : [];
    }

    /**
     * Get every PHP file beneath the given directory.
     *
     * @param  string  $path
     * @return array<int, string>
     */
    protected function phpFiles($path)
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Read the fully qualified name of the first class declared in the given file.
     *
     * @param  string  $path
     * @return string|null
     */
    protected function classFromFile($path)
    {
        $contents = @file_get_contents($path);

        if ($contents === false || ! Str::contains($contents, 'class')) {
            return null;
        }

        $tokens = @token_get_all($contents);

        if (! is_array($tokens)) {
            return null;
        }

        $namespace = '';
        $previous = null;

        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if (is_array($token) && $token[0] === T_NAMESPACE) {
                $namespace = $this->readName($tokens, $i);

                continue;
            }

            if (is_array($token) && $token[0] === T_CLASS) {
                // `Foo::class` and anonymous classes are not declarations.
                if (is_array($previous) && in_array($previous[0], [T_DOUBLE_COLON, T_NEW], true)) {
                    continue;
                }

                $name = $this->readName($tokens, $i);

                return $name === '' ? null : ltrim($namespace.'\\'.$name, '\\');
            }

            $previous = $token;
        }

        return null;
    }

    /**
     * Read the name that follows the token at the given offset.
     *
     * @param  array<int, mixed>  $tokens
     * @param  int  $offset
     * @return string
     */
    protected function readName(array $tokens, $offset)
    {
        $name = '';

        for ($i = $offset + 1; $i < count($tokens); $i++) {
            $token = $tokens[$i];

            if (is_array($token) && $token[0] === T_WHITESPACE) {
                if ($name !== '') {
                    break;
                }

                continue;
            }

            if (! is_array($token)) {
                break;
            }

            $name .= $token[1];
        }

        return trim($name, '\\ ');
    }

    /**
     * Determine if the given class is a queueable job that may be constructed.
     *
     * @param  string  $class
     * @return bool
     */
    protected function isDispatchable($class)
    {
        try {
            if (! class_exists($class) || is_a($class, CallQueuedClosure::class, true)) {
                return false;
            }

            if (! is_a($class, ShouldQueue::class, true)) {
                return false;
            }

            return (new ReflectionClass($class))->isInstantiable();
        } catch (Throwable $e) {
            return false;
        }
    }
}

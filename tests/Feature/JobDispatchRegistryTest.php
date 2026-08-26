<?php

namespace Laravel\Horizon\Tests\Feature;

use Laravel\Horizon\JobDispatchRegistry;
use Laravel\Horizon\Tests\Feature\Jobs\BasicJob;
use Laravel\Horizon\Tests\Feature\Jobs\DispatchableJob;
use Laravel\Horizon\Tests\Feature\Jobs\UndispatchableJob;
use Orchestra\Testbench\TestCase;

class JobDispatchRegistryTest extends TestCase
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return ['Laravel\Horizon\HorizonServiceProvider'];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('horizonxflow.dispatch.paths', [__DIR__.'/Jobs']);
    }

    public function test_it_discovers_queueable_jobs_within_the_configured_paths(): void
    {
        $classes = $this->classes();

        $this->assertContains(DispatchableJob::class, $classes);
        $this->assertContains(UndispatchableJob::class, $classes);
    }

    public function test_it_ignores_classes_that_are_not_queueable(): void
    {
        $this->assertNotContains(BasicJob::class, $this->classes());
    }

    public function test_it_describes_a_discovered_job(): void
    {
        $job = collect($this->registry()->dispatchable())->firstWhere('class', DispatchableJob::class);

        $this->assertSame('DispatchableJob', $job['name']);
        $this->assertSame('Laravel\Horizon\Tests\Feature\Jobs', $job['namespace']);
    }

    public function test_it_removes_denied_jobs(): void
    {
        config()->set('horizonxflow.dispatch.denied', ['Laravel\Horizon\Tests\Feature\Jobs\Undispatchable*']);

        $classes = $this->classes();

        $this->assertContains(DispatchableJob::class, $classes);
        $this->assertNotContains(UndispatchableJob::class, $classes);
    }

    public function test_it_restricts_discovered_jobs_to_the_allow_list(): void
    {
        config()->set('horizonxflow.dispatch.allowed', [DispatchableJob::class]);

        $this->assertSame([DispatchableJob::class], $this->classes());
    }

    public function test_it_allows_a_job_that_is_named_but_not_discovered(): void
    {
        config()->set('horizonxflow.dispatch.discover', false);
        config()->set('horizonxflow.dispatch.allowed', [DispatchableJob::class]);

        $this->assertSame([DispatchableJob::class], $this->classes());
    }

    public function test_denied_jobs_win_over_the_allow_list(): void
    {
        config()->set('horizonxflow.dispatch.allowed', [DispatchableJob::class]);
        config()->set('horizonxflow.dispatch.denied', [DispatchableJob::class]);

        $this->assertSame([], $this->classes());
    }

    public function test_it_lists_nothing_when_dispatching_is_disabled(): void
    {
        config()->set('horizonxflow.dispatch.enabled', false);

        $this->assertSame([], $this->classes());
    }

    public function test_it_refuses_a_class_it_does_not_list(): void
    {
        $registry = $this->registry();

        $this->assertTrue($registry->allows(DispatchableJob::class));
        $this->assertFalse($registry->allows(BasicJob::class));

        $this->expectExceptionMessage('may not be dispatched from the dashboard');

        $registry->ensureDispatchable(BasicJob::class);
    }

    public function test_it_refuses_every_class_when_dispatching_is_disabled(): void
    {
        config()->set('horizonxflow.dispatch.enabled', false);

        $this->expectExceptionMessage('Dispatching jobs from the dashboard is disabled.');

        $this->registry()->ensureDispatchable(DispatchableJob::class);
    }

    public function test_it_refuses_an_empty_class(): void
    {
        $this->expectExceptionMessage('A job class is required.');

        $this->registry()->ensureDispatchable('');
    }

    protected function registry(): JobDispatchRegistry
    {
        return new JobDispatchRegistry($this->app, $this->app['config']);
    }

    /**
     * @return array<int, string>
     */
    protected function classes(): array
    {
        return array_column($this->registry()->dispatchable(), 'class');
    }
}

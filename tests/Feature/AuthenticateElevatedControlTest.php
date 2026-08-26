<?php

namespace Laravel\Horizon\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Exceptions\ForbiddenException;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\Http\Middleware\AuthenticateElevatedControl;
use Orchestra\Testbench\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AuthenticateElevatedControlTest extends TestCase
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return ['Laravel\Horizon\HorizonServiceProvider'];
    }

    protected function tearDown(): void
    {
        Horizon::$authUsing = null;

        parent::tearDown();
    }

    public function test_it_forbids_when_the_control_gate_is_undefined_even_locally(): void
    {
        Horizon::auth(fn ($request) => true);
        $this->app['env'] = 'local';

        try {
            $this->dispatchRequest();
            $this->fail('Expected the request to be forbidden.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertStringContainsString('controlHorizon', $exception->getMessage());
        }
    }

    public function test_it_forbids_when_the_control_gate_is_undefined_in_testing(): void
    {
        Horizon::auth(fn ($request) => true);
        $this->app['env'] = 'testing';

        $this->expectException(HttpException::class);

        $this->dispatchRequest();
    }

    public function test_it_forbids_when_the_control_gate_denies(): void
    {
        Horizon::auth(fn ($request) => true);
        Gate::define('controlHorizon', fn ($user = null) => false);

        $this->expectException(ForbiddenException::class);

        $this->dispatchRequest();
    }

    public function test_it_forbids_when_the_dashboard_itself_is_closed(): void
    {
        Horizon::auth(fn ($request) => false);
        Gate::define('controlHorizon', fn ($user = null) => true);

        $this->expectException(ForbiddenException::class);

        $this->dispatchRequest();
    }

    public function test_it_passes_when_the_control_gate_grants_access(): void
    {
        Horizon::auth(fn ($request) => true);
        Gate::define('controlHorizon', fn ($user = null) => true);

        $this->assertSame('next', $this->dispatchRequest());
    }

    protected function dispatchRequest(): mixed
    {
        $request = Request::create('/horizon/api/jobs/dispatch', 'POST');
        $request->setUserResolver(fn () => new GenericUser(['id' => 1]));

        return (new AuthenticateElevatedControl)->handle($request, fn () => 'next');
    }
}

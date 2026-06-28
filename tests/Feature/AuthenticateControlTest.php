<?php

namespace Laravel\Horizon\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Exceptions\ForbiddenException;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\Http\Middleware\AuthenticateControl;
use Orchestra\Testbench\TestCase;

class AuthenticateControlTest extends TestCase
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
        Horizon::auth(fn ($request) => true);

        parent::tearDown();
    }

    public function test_it_passes_when_control_gate_is_not_defined_in_local_environments(): void
    {
        Horizon::auth(fn ($request) => true);

        $this->assertSame('next', $this->dispatch($this->actingUser()));
    }

    public function test_it_forbids_when_control_gate_is_not_defined_outside_local_environments(): void
    {
        Horizon::auth(fn ($request) => true);
        $this->app['env'] = 'production';

        $this->expectException(ForbiddenException::class);

        $this->dispatch($this->actingUser());
    }

    public function test_it_passes_when_control_gate_grants_access_outside_local_environments(): void
    {
        Horizon::auth(fn ($request) => true);
        $this->app['env'] = 'production';
        Gate::define('controlHorizon', fn ($user) => $user->email === 'op@example.com');

        $this->assertSame('next', $this->dispatch($this->actingUser('op@example.com')));
    }

    public function test_it_passes_when_control_gate_grants_access(): void
    {
        Horizon::auth(fn ($request) => true);
        Gate::define('controlHorizon', fn ($user) => $user->email === 'op@example.com');

        $this->assertSame('next', $this->dispatch($this->actingUser('op@example.com')));
    }

    public function test_it_forbids_when_control_gate_denies(): void
    {
        Horizon::auth(fn ($request) => true);
        Gate::define('controlHorizon', fn ($user) => false);

        $this->expectException(ForbiddenException::class);

        $this->dispatch($this->actingUser('viewer@example.com'));
    }

    public function test_it_forbids_when_dashboard_auth_fails(): void
    {
        Horizon::auth(fn ($request) => false);

        $this->expectException(ForbiddenException::class);

        $this->dispatch($this->actingUser());
    }

    protected function dispatch(GenericUser $user): string
    {
        $request = Request::create('/test');
        $request->setUserResolver(fn () => $user);

        return Container::getInstance()->make(AuthenticateControl::class)->handle(
            $request,
            fn ($request) => 'next'
        );
    }

    protected function actingUser(string $email = 'user@example.com'): GenericUser
    {
        $user = new GenericUser(['email' => $email]);
        Auth::shouldUse('web');

        return $user;
    }
}

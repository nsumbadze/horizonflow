<?php

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        parent::register();

        // The workbench has no app/Jobs directory, so point job discovery at
        // the demo jobs instead. `composer serve` and `composer serve:demo`
        // can then dispatch them from Live Flow.
        $this->app['config']->set('horizonxflow.dispatch.paths', [
            dirname(__DIR__, 3).'/src/Demo',
        ]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user) {
            return true;
        });

        // Dispatching a job and cancelling a run require this gate to be
        // defined explicitly — there is no environment fallback for them.
        Gate::define('controlHorizon', function ($user = null) {
            return true;
        });
    }
}

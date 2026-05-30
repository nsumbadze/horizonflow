<?php

namespace Laravel\Horizon\Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Horizon\Repositories\DatabaseQueueFlowRepository;
use Orchestra\Testbench\TestCase;

class DatabaseQueueFlowRepositoryTest extends TestCase
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return ['Laravel\Horizon\HorizonServiceProvider'];
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('queue.connections.database', [
            'driver' => 'database',
            'connection' => 'testbench',
            'table' => 'jobs',
            'queue' => 'default',
        ]);
        $app['config']->set('horizonxbrain.flow.database.discover_connections', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('jobs', function ($table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function ($table): void {
            $table->bigIncrements('id');
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    public function test_summary_reports_pending_and_failed_counts_from_database(): void
    {
        $this->seedPendingJob(queue: 'default');
        $this->seedPendingJob(queue: 'default');
        $this->seedFailedJob(queue: 'default', failedAt: Carbon::now());

        $payload = (new DatabaseQueueFlowRepository())->get();

        $this->assertSame('database', $payload['source']);
        $this->assertSame(2, $payload['summary']['pending']);
        $this->assertSame(1, $payload['summary']['failed']);
    }

    public function test_failed_in_window_only_counts_recent_failures(): void
    {
        request()->merge(['window' => 600]);

        $this->seedFailedJob(failedAt: Carbon::now()->subSeconds(120));
        $this->seedFailedJob(failedAt: Carbon::now()->subSeconds(300));
        $this->seedFailedJob(failedAt: Carbon::now()->subDay());

        $payload = (new DatabaseQueueFlowRepository())->get();

        $this->assertSame(3, $payload['summary']['failed']);
        $this->assertSame(2, $payload['summary']['failed_in_window']);
        $this->assertSame(600, $payload['summary']['window_seconds']);
    }

    public function test_window_seconds_clamps_to_safe_bounds(): void
    {
        request()->merge(['window' => 10]);

        $payload = (new DatabaseQueueFlowRepository())->get();
        $this->assertSame(60, $payload['summary']['window_seconds']);

        request()->merge(['window' => 9999999]);
        $payload = (new DatabaseQueueFlowRepository())->get();
        $this->assertSame(2592000, $payload['summary']['window_seconds']);
    }

    protected function seedPendingJob(string $queue = 'default'): void
    {
        DB::table('jobs')->insert([
            'queue' => $queue,
            'payload' => json_encode(['displayName' => 'App\\Jobs\\Demo']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => Carbon::now()->timestamp,
            'created_at' => Carbon::now()->timestamp,
        ]);
    }

    protected function seedFailedJob(string $queue = 'default', ?Carbon $failedAt = null): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => bin2hex(random_bytes(8)),
            'connection' => 'database',
            'queue' => $queue,
            'payload' => json_encode(['displayName' => 'App\\Jobs\\Failing']),
            'exception' => 'RuntimeException: nope',
            'failed_at' => ($failedAt ?? Carbon::now())->toDateTimeString(),
        ]);
    }
}

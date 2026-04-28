<?php

namespace Laravel\Horizon\Tests\Feature;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Laravel\Horizon\Http\Controllers\SupervisorControlController;
use Mockery;
use Orchestra\Testbench\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SupervisorControlControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return ['Laravel\Horizon\HorizonServiceProvider'];
    }

    public function test_pause_masters_invokes_horizon_pause_command(): void
    {
        $kernel = $this->kernelExpecting('horizon:pause', []);

        $response = (new SupervisorControlController())->pauseMasters($kernel);

        $this->assertSame('paused', $response['action']);
        $this->assertSame('horizon:pause', $response['command']);
        $this->assertSame(0, $response['exit_code']);
    }

    public function test_continue_masters_invokes_horizon_continue_command(): void
    {
        $kernel = $this->kernelExpecting('horizon:continue', []);

        $response = (new SupervisorControlController())->continueMasters($kernel);

        $this->assertSame('continued', $response['action']);
        $this->assertSame('horizon:continue', $response['command']);
    }

    public function test_pause_supervisor_passes_supervisor_name_to_horizon_command(): void
    {
        $kernel = $this->kernelExpecting('horizon:pause-supervisor', ['name' => 'orders']);

        $response = (new SupervisorControlController())->pauseSupervisor('orders', $kernel);

        $this->assertSame('paused', $response['action']);
        $this->assertSame('orders', $response['supervisor']);
    }

    public function test_pause_supervisor_404s_when_horizon_command_fails(): void
    {
        $kernel = Mockery::mock(ConsoleKernel::class);
        $kernel->shouldReceive('call')->with('horizon:pause-supervisor', ['name' => 'missing'])->andReturn(1);

        try {
            (new SupervisorControlController())->pauseSupervisor('missing', $kernel);
            $this->fail('Expected 404 abort.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    protected function kernelExpecting(string $command, array $parameters): ConsoleKernel
    {
        $kernel = Mockery::mock(ConsoleKernel::class);
        $kernel->shouldReceive('call')->once()->with($command, $parameters)->andReturn(0);
        $kernel->shouldReceive('output')->andReturn('');

        return $kernel;
    }
}

<?php

namespace Laravel\Horizon\Http\Controllers;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Laravel\Horizon\Http\Middleware\AuthenticateControl;

class SupervisorControlController extends Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->middleware(AuthenticateControl::class);
    }

    /**
     * Pause all Horizon master supervisors for this application.
     *
     * @return array<string, mixed>
     */
    public function pauseMasters(ConsoleKernel $kernel): array
    {
        return $this->runHorizonCommand($kernel, 'horizon:pause', [], 'paused');
    }

    /**
     * Continue all Horizon master supervisors for this application.
     *
     * @return array<string, mixed>
     */
    public function continueMasters(ConsoleKernel $kernel): array
    {
        return $this->runHorizonCommand($kernel, 'horizon:continue', [], 'continued');
    }

    /**
     * Pause a single Horizon supervisor.
     *
     * @return array<string, mixed>
     */
    public function pauseSupervisor(string $name, ConsoleKernel $kernel): array
    {
        return $this->runHorizonCommand(
            $kernel,
            'horizon:pause-supervisor',
            ['name' => $name],
            'paused',
            $name
        );
    }

    /**
     * Continue a single Horizon supervisor.
     *
     * @return array<string, mixed>
     */
    public function continueSupervisor(string $name, ConsoleKernel $kernel): array
    {
        return $this->runHorizonCommand(
            $kernel,
            'horizon:continue-supervisor',
            ['name' => $name],
            'continued',
            $name
        );
    }

    /**
     * Invoke a Horizon control command and return a structured response.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    protected function runHorizonCommand(
        ConsoleKernel $kernel,
        string $command,
        array $parameters,
        string $action,
        ?string $supervisor = null,
    ): array {
        $exitCode = $kernel->call($command, $parameters);

        if ($exitCode !== 0 && $supervisor !== null) {
            abort(404, 'Supervisor not found.');
        }

        return array_filter([
            'action' => $action,
            'command' => $command,
            'supervisor' => $supervisor,
            'exit_code' => $exitCode,
            'output' => trim($kernel->output()),
        ], fn ($value): bool => $value !== null);
    }
}

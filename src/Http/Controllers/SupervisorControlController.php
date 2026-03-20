<?php

namespace Laravel\Horizon\Http\Controllers;

use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\MasterSupervisor;

class SupervisorControlController extends Controller
{
    /**
     * Pause all Horizon master supervisors for this application.
     *
     * @return array<string, mixed>
     */
    public function pauseMasters(MasterSupervisorRepository $masters): array
    {
        return $this->signalMasters($masters, SIGUSR2, 'paused');
    }

    /**
     * Continue all Horizon master supervisors for this application.
     *
     * @return array<string, mixed>
     */
    public function continueMasters(MasterSupervisorRepository $masters): array
    {
        return $this->signalMasters($masters, SIGCONT, 'continued');
    }

    /**
     * Pause a single Horizon supervisor.
     *
     * @return array<string, mixed>
     */
    public function pauseSupervisor(string $name, SupervisorRepository $supervisors): array
    {
        return $this->signalSupervisor($supervisors, $name, SIGUSR2, 'paused');
    }

    /**
     * Continue a single Horizon supervisor.
     *
     * @return array<string, mixed>
     */
    public function continueSupervisor(string $name, SupervisorRepository $supervisors): array
    {
        return $this->signalSupervisor($supervisors, $name, SIGCONT, 'continued');
    }

    /**
     * @return array<string, mixed>
     */
    protected function signalMasters(MasterSupervisorRepository $masters, int $signal, string $action): array
    {
        $processes = collect($masters->all())
            ->filter(fn (object $master): bool => Str::startsWith($master->name, MasterSupervisor::basename()))
            ->pluck('pid')
            ->filter()
            ->values();

        $results = $processes->map(fn (int $pid): array => $this->signalProcess($pid, $signal));

        return [
            'action' => $action,
            'count' => $results->where('ok', true)->count(),
            'results' => $results->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function signalSupervisor(SupervisorRepository $supervisors, string $name, int $signal, string $action): array
    {
        $supervisor = collect($supervisors->all())->first(function (object $supervisor) use ($name): bool {
            return $supervisor->name === $name
                || Str::endsWith($supervisor->name, $name);
        });

        if ($supervisor === null || empty($supervisor->pid)) {
            abort(404, 'Supervisor not found.');
        }

        return [
            'action' => $action,
            'supervisor' => $supervisor->name,
            'result' => $this->signalProcess((int) $supervisor->pid, $signal),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function signalProcess(int $pid, int $signal): array
    {
        $ok = posix_kill($pid, $signal);

        return [
            'pid' => $pid,
            'ok' => $ok,
            'error' => $ok ? null : posix_strerror(posix_get_last_error()),
        ];
    }
}

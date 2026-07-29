<?php

namespace Laravel\Horizon\Http\Controllers;

use Illuminate\Http\Request;
use Laravel\Horizon\Contracts\JobControlRepository;
use Laravel\Horizon\Http\Middleware\AuthenticateControl;

class QueueControlController extends Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->middleware(AuthenticateControl::class);
    }

    /**
     * Pause one Redis queue while continuing to accept dispatched jobs.
     *
     * @return array<string, mixed>
     */
    public function pause(Request $request, JobControlRepository $controls): array
    {
        [$connection, $queue] = $this->queueTarget($request);

        try {
            $pause = $controls->pauseQueue(
                $connection,
                $queue,
                $this->operator($request)
            );
        } catch (\InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return array_merge(['action' => 'paused'], $pause);
    }

    /**
     * Resume one Redis queue.
     *
     * @return array<string, mixed>
     */
    public function resume(Request $request, JobControlRepository $controls): array
    {
        [$connection, $queue] = $this->queueTarget($request);

        try {
            $resume = $controls->resumeQueue($connection, $queue);
        } catch (\InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return array_merge(['action' => 'resumed'], $resume);
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function queueTarget(Request $request): array
    {
        $connection = trim((string) $request->input('connection', ''));
        $queue = trim((string) $request->input('queue', ''));

        if ($connection === '' || $queue === '') {
            abort(422, 'A queue connection and name are required.');
        }

        return [$connection, $queue];
    }

    protected function operator(Request $request): ?string
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        $identifier = method_exists($user, 'getAuthIdentifier')
            ? $user->getAuthIdentifier()
            : null;

        $label = $identifier !== null
            ? get_class($user).':'.$identifier
            : get_class($user);

        return substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $label) ?: '', 0, 190) ?: null;
    }
}

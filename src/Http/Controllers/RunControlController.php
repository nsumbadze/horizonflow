<?php

namespace Laravel\Horizon\Http\Controllers;

use Illuminate\Http\Request;
use InvalidArgumentException;
use Laravel\Horizon\Contracts\JobControlRepository;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Http\Middleware\AuthenticateElevatedControl;
use Laravel\Horizon\JobRunInspector;

class RunControlController extends Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->middleware(AuthenticateElevatedControl::class);
    }

    /**
     * List the runs that are currently cancelled.
     *
     * @return array<string, mixed>
     */
    public function index(JobControlRepository $controls): array
    {
        return ['runs' => $controls->cancelledRuns()];
    }

    /**
     * Get the run the given job belongs to.
     *
     * @return array<string, mixed>
     */
    public function show(
        string $id,
        JobRepository $jobs,
        JobRunInspector $runs,
        JobControlRepository $controls
    ): array {
        $group = $this->groupForJob($id, $jobs, $runs);

        return [
            'id' => $id,
            'group' => $group,
            'run' => $group === null ? null : $controls->cancelledRun($group),
        ];
    }

    /**
     * Cancel a whole run, by group or by a job that belongs to it.
     *
     * @return array<string, mixed>
     */
    public function store(
        Request $request,
        JobControlRepository $controls,
        JobRepository $jobs,
        JobRunInspector $runs
    ): array {
        $group = $this->requestedGroup($request, $jobs, $runs);

        try {
            return array_merge(['action' => 'cancelled'], $controls->cancelRun(
                $group,
                $this->operator($request),
                $this->requestedTtl($request)
            ));
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }
    }

    /**
     * Lift a run cancellation.
     *
     * @return array<string, mixed>
     */
    public function release(Request $request, JobControlRepository $controls): array
    {
        $group = $this->group($request);

        return [
            'action' => 'released',
            'group' => $group,
            'released' => $controls->releaseRun($group),
        ];
    }

    /**
     * Get the run the request is addressing.
     */
    protected function requestedGroup(Request $request, JobRepository $jobs, JobRunInspector $runs): string
    {
        if (trim((string) $request->input('group', '')) !== '') {
            return $this->group($request);
        }

        $id = trim((string) $request->input('job', ''));

        if ($id === '') {
            abort(422, 'A run group or a job that belongs to the run is required.');
        }

        $group = $this->groupForJob($id, $jobs, $runs);

        if ($group === null) {
            abort(422, 'That job does not declare a run, so there is no run to cancel. Add a cancellationGroup() method to the job.');
        }

        return $group;
    }

    /**
     * Read the run a stored job belongs to.
     */
    protected function groupForJob(string $id, JobRepository $jobs, JobRunInspector $runs): ?string
    {
        if (! preg_match('/\A[A-Za-z0-9-]{1,128}\z/', $id)) {
            abort(422, 'The job ID is invalid.');
        }

        $job = $jobs->getJobs([$id])->first();

        if ($job === null) {
            abort(404, 'Job not found.');
        }

        $payload = json_decode((string) ($job->payload ?? ''), true);

        return is_array($payload) ? $runs->groupForPayload($payload) : null;
    }

    /**
     * Get the validated run group from the request.
     */
    protected function group(Request $request): string
    {
        $group = trim((string) $request->input('group', ''));

        if ($group === '' || ! preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $group)) {
            abort(422, 'The run group may only contain letters, numbers, dashes, underscores, dots, and colons.');
        }

        return $group;
    }

    /**
     * Get the requested lifetime for the cancellation, in seconds.
     */
    protected function requestedTtl(Request $request): ?int
    {
        $ttl = $request->input('ttl');

        if (is_null($ttl) || $ttl === '') {
            return null;
        }

        if (! is_numeric($ttl) || (string) (int) $ttl !== (string) $ttl) {
            abort(422, 'The lifetime must be given as a whole number of seconds.');
        }

        $ttl = (int) $ttl;

        if ($ttl < 60 || $ttl > 604800) {
            abort(422, 'The lifetime must be between 60 and 604800 seconds.');
        }

        return $ttl;
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

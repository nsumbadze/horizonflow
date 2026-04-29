<?php

namespace Laravel\Horizon\Http\Controllers;

use Illuminate\Http\Request;
use Laravel\Horizon\Contracts\QueueFlowRepository;
use Laravel\Horizon\QueueFlow\QueueFlowEventsBuilder;
use Laravel\Horizon\QueueFlow\QueueFlowGraphBuilder;
use Laravel\Horizon\QueueFlow\QueueFlowQueueJobsBuilder;
use Laravel\Horizon\QueueFlow\QueueFlowQueuesBuilder;
use Laravel\Horizon\QueueFlow\QueueFlowSummaryBuilder;

class QueueFlowController extends Controller
{
    /**
     * Maximum number of events returned in a single events poll.
     */
    protected const EVENTS_HARD_LIMIT = 200;

    /**
     * Maximum number of queue rows returned in a single queues poll.
     */
    protected const QUEUES_HARD_LIMIT = 500;

    /**
     * Get the full flow payload. Kept for backwards compatibility while the
     * frontend migrates to the focused endpoints below.
     *
     * @return array<string, mixed>
     */
    public function index(QueueFlowRepository $flow): array
    {
        return $flow->get();
    }

    /**
     * Get the high-level summary block plus errors and source metadata.
     *
     * @return array<string, mixed>
     */
    public function summary(QueueFlowSummaryBuilder $builder): array
    {
        return $builder->build();
    }

    /**
     * Get nodes and edges for the flow graph.
     *
     * @return array<string, mixed>
     */
    public function graph(QueueFlowGraphBuilder $builder): array
    {
        return $builder->build();
    }

    /**
     * Get the queue table rows with optional filter and sort.
     *
     * @return array<string, mixed>
     */
    public function queues(Request $request, QueueFlowQueuesBuilder $builder): array
    {
        return $builder->build(
            filter: $request->query('filter') !== null ? (string) $request->query('filter') : null,
            sort: $request->query('sort') !== null ? (string) $request->query('sort') : null,
            direction: $request->query('direction') !== null ? (string) $request->query('direction') : null,
            limit: $this->clampLimit($request, 200, self::QUEUES_HARD_LIMIT),
        );
    }

    /**
     * Get recent jobs and job classes for a specific queue.
     *
     * @return array<string, mixed>
     */
    public function queueJobs(Request $request, QueueFlowQueueJobsBuilder $builder): array
    {
        $key = (string) $request->query('key', '');

        if ($key === '') {
            abort(400, 'A queue key is required.');
        }

        $payload = $builder->build($key);

        if ($payload === null) {
            abort(404, 'Queue not found.');
        }

        return $payload;
    }

    /**
     * Get recent activity events, optionally filtered to entries newer than
     * the timestamp the client last received.
     *
     * @return array<string, mixed>
     */
    public function events(Request $request, QueueFlowEventsBuilder $builder): array
    {
        $since = $request->query('since');

        return $builder->build(
            since: $since !== null && is_numeric($since) ? (int) $since : null,
            limit: $this->clampLimit($request, 60, self::EVENTS_HARD_LIMIT),
        );
    }

    protected function clampLimit(Request $request, int $default, int $max): int
    {
        $value = $request->query('limit');

        if (! is_numeric($value)) {
            return $default;
        }

        return max(1, min((int) $value, $max));
    }
}

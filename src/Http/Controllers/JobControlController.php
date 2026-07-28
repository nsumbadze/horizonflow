<?php

namespace Laravel\Horizon\Http\Controllers;

use Illuminate\Http\Request;
use Laravel\Horizon\Contracts\JobControlRepository;
use Laravel\Horizon\Http\Middleware\AuthenticateControl;

class JobControlController extends Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->middleware(AuthenticateControl::class);
    }

    /**
     * Cancel a pending job or request cooperative cancellation.
     *
     * @return array<string, mixed>
     */
    public function cancel(
        string $id,
        Request $request,
        JobControlRepository $controls,
    ): array {
        $result = $controls->cancel($id, $this->operator($request));

        match ($result['action'] ?? null) {
            'invalid' => abort(422, 'The job ID is invalid.'),
            'not_found' => abort(404, 'Job not found.'),
            'conflict' => abort(409, 'The job has already finished and cannot be cancelled.'),
            default => null,
        };

        return $result;
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

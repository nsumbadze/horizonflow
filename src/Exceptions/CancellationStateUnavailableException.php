<?php

namespace Laravel\Horizon\Exceptions;

use RuntimeException;

/**
 * Thrown when a worker cannot tell whether a job's run has been cancelled.
 *
 * The worker's own exception handling takes over, releasing the job for a
 * later attempt (or failing it once it is out of attempts) rather than
 * running work an operator may have asked to stop.
 */
class CancellationStateUnavailableException extends RuntimeException
{
}

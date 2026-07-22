<?php

use Illuminate\Support\Facades\Route;

Route::prefix('api')->group(function () {
    // Dashboard Routes...
    Route::get('/stats', 'DashboardStatsController@index')->name('horizon.stats.index');

    // Workload Routes...
    Route::get('/workload', 'WorkloadController@index')->name('horizon.workload.index');

    // Queue Flow Routes...
    Route::get('/flow', 'QueueFlowController@index')->name('horizonxbrain.flow.index');
    Route::get('/flow/summary', 'QueueFlowController@summary')->name('horizonxbrain.flow.summary');
    Route::get('/flow/graph', 'QueueFlowController@graph')->name('horizonxbrain.flow.graph');
    Route::get('/flow/queues', 'QueueFlowController@queues')->name('horizonxbrain.flow.queues');
    Route::get('/flow/queue-jobs', 'QueueFlowController@queueJobs')->name('horizonxbrain.flow.queue-jobs');
    Route::get('/flow/events', 'QueueFlowController@events')->name('horizonxbrain.flow.events');
    Route::get('/flow/incidents', 'IncidentController@index')->name('horizonxbrain.flow.incidents');

    // Master Supervisor Routes...
    Route::get('/masters', 'MasterSupervisorController@index')->name('horizon.masters.index');
    Route::post('/masters/pause', 'SupervisorControlController@pauseMasters')->name('horizonxbrain.masters.pause');
    Route::post('/masters/continue', 'SupervisorControlController@continueMasters')->name('horizonxbrain.masters.continue');
    Route::post('/supervisors/{name}/pause', 'SupervisorControlController@pauseSupervisor')->name('horizonxbrain.supervisors.pause');
    Route::post('/supervisors/{name}/continue', 'SupervisorControlController@continueSupervisor')->name('horizonxbrain.supervisors.continue');

    // Monitoring Routes...
    Route::get('/monitoring', 'MonitoringController@index')->name('horizon.monitoring.index');
    Route::post('/monitoring', 'MonitoringController@store')->name('horizon.monitoring.store');
    Route::get('/monitoring/{tag}', 'MonitoringController@paginate')->name('horizon.monitoring-tag.paginate');
    Route::delete('/monitoring/{tag}', 'MonitoringController@destroy')
        ->name('horizon.monitoring-tag.destroy')
        ->where('tag', '.*');

    // Job Metric Routes...
    Route::get('/metrics/jobs', 'JobMetricsController@index')->name('horizon.jobs-metrics.index');
    Route::get('/metrics/jobs/{id}', 'JobMetricsController@show')->name('horizon.jobs-metrics.show');

    // Queue Metric Routes...
    Route::get('/metrics/queues', 'QueueMetricsController@index')->name('horizon.queues-metrics.index');
    Route::get('/metrics/queues/{id}', 'QueueMetricsController@show')->name('horizon.queues-metrics.show');

    // Batches Routes...
    Route::get('/batches', 'BatchesController@index')->name('horizon.jobs-batches.index');
    Route::get('/batches/{id}', 'BatchesController@show')->name('horizon.jobs-batches.show');
    Route::post('/batches/retry/{id}', 'BatchesController@retry')->name('horizon.jobs-batches.retry');

    // Job Routes...
    Route::get('/jobs/pending', 'PendingJobsController@index')->name('horizon.pending-jobs.index');
    Route::get('/jobs/completed', 'CompletedJobsController@index')->name('horizon.completed-jobs.index');
    Route::get('/jobs/silenced', 'SilencedJobsController@index')->name('horizon.silenced-jobs.index');
    Route::get('/jobs/failed', 'FailedJobsController@index')->name('horizon.failed-jobs.index');
    Route::get('/jobs/failed/{id}', 'FailedJobsController@show')->name('horizon.failed-jobs.show');
    Route::post('/jobs/retry/{id}', 'RetryController@store')->name('horizon.retry-jobs.show');
    Route::get('/jobs/{id}', 'JobsController@show')->name('horizon.jobs.show');
});

// Catch-all Route...
Route::get('/{view?}', 'HomeController@index')->where('view', '(.*)')->name('horizon.index');

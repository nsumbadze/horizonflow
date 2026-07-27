<?php

namespace Laravel\Horizon\Tests\Feature;

use DateTimeImmutable;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Queue\CallQueuedClosure;
use Laravel\Horizon\Exceptions\InvalidJobParameterException;
use Laravel\Horizon\JobParameterInspector;
use Laravel\Horizon\Tests\Feature\Jobs\JobWithParameters;
use Orchestra\Testbench\TestCase;

class JobParameterInspectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('app.key', 'base64:UTyp33UhGolgzCK5CJmT+hNHcA+dJyp3+oINtX+VoPI=');
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return ['Laravel\Horizon\HorizonServiceProvider'];
    }

    public function test_it_describes_the_constructor_parameters_of_a_job(): void
    {
        $description = $this->inspector()->inspect($this->payloadFor($this->job()));

        $this->assertTrue($description['editable']);
        $this->assertSame(JobWithParameters::class, $description['class']);

        $parameters = collect($description['parameters'])->keyBy('name');

        $this->assertSame(['name', 'attempts', 'ratio', 'notify', 'options', 'reason', 'scheduledFor'], $parameters->keys()->all());
        $this->assertSame('import', $parameters['name']['value']);
        $this->assertSame(3, $parameters['attempts']['value']);
        $this->assertSame(0.5, $parameters['ratio']['value']);
        $this->assertTrue($parameters['notify']['value']);
        $this->assertSame(['chunk' => 100], $parameters['options']['value']);
        $this->assertNull($parameters['reason']['value']);
        $this->assertTrue($parameters['reason']['nullable']);
    }

    public function test_it_marks_object_parameters_as_read_only(): void
    {
        $parameters = collect($this->inspector()->inspect($this->payloadFor($this->job()))['parameters'])->keyBy('name');

        $this->assertFalse($parameters['scheduledFor']['editable']);
        $this->assertSame('DateTimeImmutable', $parameters['scheduledFor']['preview']);
        $this->assertSame('Only scalar and array parameters may be edited.', $parameters['scheduledFor']['reason']);
    }

    public function test_it_reports_jobs_that_may_not_be_edited(): void
    {
        $missing = $this->inspector()->inspect(['data' => ['commandName' => 'App\\Jobs\\Missing', 'command' => 'O:1:"a":0:{}']]);

        $this->assertFalse($missing['editable']);
        $this->assertSame('The [App\\Jobs\\Missing] class is not available in this application.', $missing['reason']);

        $closure = $this->inspector()->inspect([
            'data' => ['commandName' => CallQueuedClosure::class, 'command' => 'O:1:"a":0:{}'],
        ]);

        $this->assertFalse($closure['editable']);
        $this->assertSame('Queued closures do not expose constructor parameters.', $closure['reason']);

        $unreadable = $this->inspector()->inspect(['data' => ['commandName' => JobWithParameters::class, 'command' => 'O:1:']]);

        $this->assertFalse($unreadable['editable']);
        $this->assertStringContainsString('could not be read', $unreadable['reason']);
    }

    public function test_it_applies_and_casts_parameter_overrides(): void
    {
        $payload = $this->inspector()->applyOverrides($this->payloadFor($this->job()), [
            'name' => 'export',
            'attempts' => '9',
            'ratio' => '1.25',
            'notify' => 'false',
            'options' => ['chunk' => 250, 'dry_run' => true],
            'reason' => null,
        ]);

        $command = unserialize($payload['data']['command']);

        $this->assertSame('export', $command->name);
        $this->assertSame(9, $command->attempts);
        $this->assertSame(1.25, $command->ratio);
        $this->assertFalse($command->notify);
        $this->assertSame(['chunk' => 250, 'dry_run' => true], $command->options);
        $this->assertNull($command->reason);
    }

    public function test_it_returns_the_payload_untouched_without_overrides(): void
    {
        $payload = $this->payloadFor($this->job());

        $this->assertSame($payload, $this->inspector()->applyOverrides($payload, []));
    }

    public function test_it_rejects_unknown_parameters(): void
    {
        $this->expectException(InvalidJobParameterException::class);
        $this->expectExceptionMessage('The job does not accept a [unknown] parameter.');

        $this->inspector()->applyOverrides($this->payloadFor($this->job()), ['unknown' => 1]);
    }

    public function test_it_rejects_read_only_parameters(): void
    {
        $this->expectException(InvalidJobParameterException::class);
        $this->expectExceptionMessage('The [scheduledFor] parameter may not be edited.');

        $this->inspector()->applyOverrides($this->payloadFor($this->job()), ['scheduledFor' => 'tomorrow']);
    }

    public function test_it_rejects_values_that_do_not_match_the_parameter_type(): void
    {
        $this->expectException(InvalidJobParameterException::class);
        $this->expectExceptionMessage('The [attempts] parameter must be an integer.');

        $this->inspector()->applyOverrides($this->payloadFor($this->job()), ['attempts' => '1.5']);
    }

    public function test_it_rejects_null_for_parameters_that_are_not_nullable(): void
    {
        $this->expectException(InvalidJobParameterException::class);
        $this->expectExceptionMessage('The [name] parameter may not be null.');

        $this->inspector()->applyOverrides($this->payloadFor($this->job()), ['name' => null]);
    }

    public function test_it_rejects_objects_and_nested_objects(): void
    {
        $this->expectException(InvalidJobParameterException::class);
        $this->expectExceptionMessage('The [options] parameter only accepts scalar values and plain arrays.');

        $this->inspector()->applyOverrides($this->payloadFor($this->job()), [
            'options' => ['when' => new DateTimeImmutable()],
        ]);
    }

    public function test_it_reads_and_rewrites_encrypted_payloads(): void
    {
        $encrypter = $this->app->make(Encrypter::class);

        $payload = [
            'data' => [
                'commandName' => JobWithParameters::class,
                'command' => $encrypter->encrypt(serialize($this->job())),
            ],
        ];

        $this->assertTrue($this->inspector()->inspect($payload)['editable']);

        $updated = $this->inspector()->applyOverrides($payload, ['name' => 'encrypted']);

        $this->assertNotSame($payload['data']['command'], $updated['data']['command']);
        $this->assertSame('encrypted', unserialize($encrypter->decrypt($updated['data']['command']))->name);
    }

    /**
     * Get the inspector instance under test.
     */
    protected function inspector(): JobParameterInspector
    {
        return $this->app->make(JobParameterInspector::class);
    }

    /**
     * Get the job used throughout the test.
     */
    protected function job(): JobWithParameters
    {
        return new JobWithParameters('import', 3, 0.5, true, ['chunk' => 100], null, new DateTimeImmutable('2026-01-01'));
    }

    /**
     * Build a queue payload for the given job.
     *
     * @return array<string, mixed>
     */
    protected function payloadFor(object $job): array
    {
        return [
            'displayName' => get_class($job),
            'data' => [
                'commandName' => get_class($job),
                'command' => serialize($job),
            ],
        ];
    }
}

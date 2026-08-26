<?php

namespace Laravel\Horizon\Tests\Feature;

use Illuminate\Container\Container;
use Laravel\Horizon\JobRunInspector;
use Laravel\Horizon\Tests\Feature\Jobs\BasicJob;
use Laravel\Horizon\Tests\Feature\Jobs\ChainedRunJob;
use PHPUnit\Framework\TestCase;

class JobRunInspectorTest extends TestCase
{
    public function test_it_reads_the_group_a_job_declares(): void
    {
        $this->assertSame('test-run:7', $this->inspector()->groupForPayload(
            $this->payload(new ChainedRunJob(7))
        ));
    }

    public function test_a_job_that_declares_no_group_belongs_to_no_run(): void
    {
        $this->assertNull($this->inspector()->groupForPayload($this->payload(new BasicJob)));
    }

    public function test_it_ignores_a_payload_without_a_command(): void
    {
        $this->assertNull($this->inspector()->groupForPayload([]));
        $this->assertNull($this->inspector()->groupForPayload(['data' => ['command' => '']]));
    }

    public function test_it_ignores_an_unreadable_command(): void
    {
        $this->assertNull($this->inspector()->groupForPayload([
            'data' => ['commandName' => ChainedRunJob::class, 'command' => 'O:not-a-real-object'],
        ]));
    }

    public function test_it_ignores_an_encrypted_command_when_no_encrypter_is_available(): void
    {
        $this->assertNull($this->inspector()->groupForPayload([
            'data' => ['commandName' => ChainedRunJob::class, 'command' => 'ZW5jcnlwdGVk'],
        ]));
    }

    public function test_it_does_not_unserialize_a_job_that_never_opted_in(): void
    {
        // A payload naming a class with no cancellationGroup() must not be
        // unserialized at all, so a hostile command string is never touched.
        $this->assertNull($this->inspector()->groupForPayload([
            'data' => ['commandName' => BasicJob::class, 'command' => 'O:8:\\"Evil\\":0:{}'],
        ]));
    }

    public function test_it_ignores_a_payload_with_no_class_name(): void
    {
        $this->assertNull($this->inspector()->groupForPayload([
            'data' => ['command' => serialize(new ChainedRunJob(7))],
        ]));
    }

    public function test_it_refuses_a_group_that_could_address_other_keys(): void
    {
        $inspector = $this->inspector();

        $this->assertNull($inspector->normalize('test run/2'));
        $this->assertNull($inspector->normalize('test-run:'.str_repeat('x', 200)));
        $this->assertNull($inspector->normalize(''));
        $this->assertNull($inspector->normalize(42));

        $this->assertSame('citrus-sync:2', $inspector->normalize('  citrus-sync:2  '));
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(object $command): array
    {
        return ['data' => ['commandName' => get_class($command), 'command' => serialize($command)]];
    }

    protected function inspector(): JobRunInspector
    {
        return new JobRunInspector(new Container);
    }
}

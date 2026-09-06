<?php

namespace Iocod\Yardmaster\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Str;
use Iocod\Yardmaster\Events\ActionPerformed;
use Iocod\Yardmaster\Support\Cast;

/**
 * Retries failed jobs on any driver.
 *
 * Retry is the one control that works everywhere, because it goes through the
 * failed-job provider rather than the queue driver: the payload is already
 * stored, so pushing it back needs nothing the driver cannot do. That is why it
 * is not a capability — there is no driver on which it is unavailable.
 */
class JobRetrier
{
    public function __construct(
        protected FailedJobProviderInterface $failed,
        protected QueueFactory $queue,
        protected Dispatcher $events,
    ) {}

    /**
     * Retry one failed job by its uuid. Returns false when it is already gone.
     */
    public function retry(string $uuid): bool
    {
        $job = $this->failed->find($uuid);

        if ($job === null) {
            return false;
        }

        $payload = $this->rehydrate($job, $uuid);
        $connection = Cast::string(data_get($job, 'connection'));
        $queue = Cast::string(data_get($job, 'queue'));

        $this->queue->connection($connection)->pushRaw($payload, $queue);

        // Only forget the original once the replacement is safely queued.
        // Reversing this loses the job outright if the push fails.
        $this->failed->forget($uuid);

        $this->events->dispatch(new ActionPerformed(
            action: 'retry_failed',
            connectionName: $connection,
            driver: 'failed',
            queue: $queue,
            target: $uuid,
            at: microtime(true),
        ));

        return true;
    }

    /**
     * @param  array<int, string>  $uuids
     * @return array{retried: int, missing: int}
     */
    public function retryMany(array $uuids): array
    {
        $retried = 0;

        foreach ($uuids as $uuid) {
            $this->retry($uuid) ? $retried++ : null;
        }

        return ['retried' => $retried, 'missing' => count($uuids) - $retried];
    }

    public function forget(string $uuid): bool
    {
        $job = $this->failed->find($uuid);

        if ($job === null) {
            return false;
        }

        $this->failed->forget($uuid);

        $this->events->dispatch(new ActionPerformed(
            action: 'forget_failed',
            connectionName: Cast::string(data_get($job, 'connection')),
            driver: 'failed',
            queue: Cast::string(data_get($job, 'queue')),
            target: $uuid,
            at: microtime(true),
        ));

        return true;
    }

    /**
     * Give the retried job a fresh uuid so its attempts are recorded as a new
     * run rather than silently merging with the failed one.
     *
     * The original identifier is passed in rather than read back off the
     * record: the two shipped failed-job providers disagree about whether it
     * lives under 'id' or 'uuid', and the caller already knows it.
     */
    protected function rehydrate(mixed $job, string $uuid): string
    {
        $payload = json_decode(Cast::string(data_get($job, 'payload')), true);

        if (! is_array($payload)) {
            return Cast::string(data_get($job, 'payload'));
        }

        $payload['uuid'] = (string) Str::uuid();
        $payload['attempts'] = 0;
        $payload['retry_of'] = $uuid;

        return json_encode($payload) ?: Cast::string(data_get($job, 'payload'));
    }
}

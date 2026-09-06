<?php

namespace Iocod\Yardmaster\Concerns;

use Illuminate\Redis\Connections\Connection;

trait CallsRedis
{
    abstract protected function redis(): Connection;

    /**
     * Dispatch a command whose signature differs between the two Redis clients.
     *
     * Laravel's PhpRedisConnection normalises several commands to predis'
     * shape, so the call is correct on both — but the connection is abstract,
     * so which concrete signature applies is only knowable at runtime. Calling
     * dynamically states that honestly instead of asserting a client the caller
     * has not got.
     *
     * @param  array<int, mixed>  $arguments
     */
    protected function call(string $method, array $arguments): mixed
    {
        $connection = $this->redis();

        return $connection->{$method}(...$arguments);
    }
}

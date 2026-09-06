<?php

namespace Abhishek\Yardmaster\Http\Controllers;

use Abhishek\Yardmaster\Exceptions\UnsupportedCapability;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;
use Throwable;

abstract class Controller extends BaseController
{
    /**
     * Every API response goes through here.
     *
     * JSON collapses 1.0 to 1, so a failure rate that happens to land on a
     * whole number changes type between one response and the next. Preserving
     * the fraction keeps the contract stable for strictly typed clients.
     *
     * @param  array<string, mixed>  $data
     */
    protected function json(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Run a capability-gated operation, translating a refusal into a response
     * the dashboard can render.
     *
     * The UI is expected to have disabled the control already — this is the
     * safety net for a stale page or a direct API call, and it answers with the
     * same reason the tooltip would have shown.
     */
    protected function gated(Closure $operation): JsonResponse
    {
        try {
            return $this->json($operation());
        } catch (UnsupportedCapability $e) {
            return $this->json([
                'message' => $e->getMessage(),
                'capability' => $e->capability->value,
                'driver' => $e->driver,
                'connection' => $e->connectionName,
            ], 422);
        } catch (Throwable $e) {
            return $this->json([
                'message' => 'The queue driver refused the operation.',
                'detail' => $e->getMessage(),
            ], 502);
        }
    }
}

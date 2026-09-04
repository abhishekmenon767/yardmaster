<?php

namespace Iocod\Yardmaster\Http\Controllers;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Iocod\Yardmaster\Drivers\AdapterManager;
use Iocod\Yardmaster\Drivers\Capability;

/**
 * What the dashboard needs before it can render anything: which connections
 * exist, what each of them can actually do, and what this viewer is allowed to
 * do.
 *
 * The UI renders controls from this response. It never assumes a capability
 * from a driver name.
 */
class MetaController extends Controller
{
    public function __invoke(Request $request, AdapterManager $adapters, Gate $gate): JsonResponse
    {
        $connections = [];

        foreach ($adapters->all() as $name => $adapter) {
            $capabilities = [];

            foreach (Capability::cases() as $capability) {
                $supported = $adapter->supports($capability);

                $capabilities[$capability->value] = [
                    'supported' => $supported,
                    // The reason travels with the capability so a disabled
                    // control can say why without the UI inventing wording.
                    'reason' => $supported ? null : sprintf(
                        'The %s driver cannot %s.',
                        $adapter->driver(),
                        lcfirst($capability->label()),
                    ),
                ];
            }

            $connections[] = [
                'name' => $name,
                'driver' => $adapter->driver(),
                'capabilities' => $capabilities,
            ];
        }

        return $this->json([
            'connections' => $connections,
            'capabilities' => array_map(static fn (Capability $c) => [
                'value' => $c->value,
                'label' => $c->label(),
                'destructive' => $c->isDestructive(),
            ], Capability::cases()),
            'can' => [
                'view' => true,
                'manage' => $gate->allows('manageYardmaster', [$request->user()]),
            ],
            'statuses' => ['processing', 'processed', 'released', 'failed', 'timed_out'],
        ]);
    }
}

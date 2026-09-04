<?php

namespace Iocod\Yardmaster\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Iocod\Yardmaster\Actions\JobRetrier;
use Iocod\Yardmaster\Repositories\RunRepository;

/**
 * Failed jobs, and the two things an operator wants to do with them.
 *
 * Both act on a list rather than a single id, because a bad deploy produces
 * thousands of identical failures and retrying them one row at a time is not a
 * workflow anyone will use. Grouping them into issues arrives in phase 5.
 */
class FailureController extends Controller
{
    public function index(Request $request, RunRepository $runs): JsonResponse
    {
        return $this->json($runs->paginate(
            filters: [
                'failed' => true,
                'connection' => $request->query('connection'),
                'queue' => $request->query('queue'),
                'job_class' => $request->query('job_class'),
                'search' => $request->query('search'),
                'from' => $request->query('from'),
                'to' => $request->query('to'),
            ],
            page: (int) $request->query('page', 1),
            perPage: (int) $request->query('per_page', 50),
        ));
    }

    public function retry(Request $request, JobRetrier $retrier): JsonResponse
    {
        return $this->json($retrier->retryMany($this->uuids($request)));
    }

    public function forget(Request $request, JobRetrier $retrier): JsonResponse
    {
        $forgotten = 0;

        foreach ($this->uuids($request) as $uuid) {
            $retrier->forget($uuid) ? $forgotten++ : null;
        }

        return $this->json(['forgotten' => $forgotten]);
    }

    /**
     * @return array<int, string>
     */
    protected function uuids(Request $request): array
    {
        $uuids = $request->input('uuids', []);

        if (is_string($uuids)) {
            $uuids = [$uuids];
        }

        return array_values(array_filter(
            array_map(static fn ($uuid) => is_string($uuid) ? $uuid : null, (array) $uuids),
        ));
    }
}

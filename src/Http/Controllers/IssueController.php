<?php

namespace Iocod\Yardmaster\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Iocod\Yardmaster\Actions\JobRetrier;
use Iocod\Yardmaster\Repositories\IssueRepository;

/**
 * Failures as problems rather than rows.
 */
class IssueController extends Controller
{
    public function index(Request $request, IssueRepository $issues): JsonResponse
    {
        return $this->json($issues->paginate(
            filters: [
                'status' => $request->query('status', 'open'),
                'search' => $request->query('search'),
                'job_class' => $request->query('job_class'),
            ],
            page: (int) $request->query('page', 1),
            perPage: (int) $request->query('per_page', 50),
        ));
    }

    public function show(string $fingerprint, IssueRepository $issues): JsonResponse
    {
        $issue = $issues->find($fingerprint);

        if ($issue === null) {
            return $this->json(['message' => 'That issue has aged out or never existed.'], 404);
        }

        return $this->json(['data' => $issue]);
    }

    /**
     * Retry every failed job in one issue.
     *
     * This is the whole point of grouping: a bad deploy is one decision to
     * retry, not forty thousand.
     */
    public function retry(string $fingerprint, Request $request, IssueRepository $issues, JobRetrier $retrier): JsonResponse
    {
        $limit = max(1, min(5000, (int) $request->input('limit', 1000)));

        return $this->json($retrier->retryMany($issues->jobUuids($fingerprint, $limit)));
    }

    public function status(string $fingerprint, Request $request, IssueRepository $issues): JsonResponse
    {
        $status = (string) $request->input('status', 'open');

        $actor = $request->user();
        $name = $actor === null ? null : (string) (data_get($actor, 'name') ?? data_get($actor, 'email') ?? '');

        if (! $issues->setStatus($fingerprint, $status, $name)) {
            return $this->json([
                'message' => 'Status must be one of open, ignored or resolved.',
            ], 422);
        }

        return $this->json(['data' => $issues->find($fingerprint)]);
    }
}

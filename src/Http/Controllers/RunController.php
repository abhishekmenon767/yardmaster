<?php

namespace Iocod\Yardmaster\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Iocod\Yardmaster\Repositories\RunRepository;

class RunController extends Controller
{
    public function index(Request $request, RunRepository $runs): JsonResponse
    {
        return $this->json($runs->paginate(
            filters: $this->filters($request),
            page: (int) $request->query('page', 1),
            perPage: (int) $request->query('per_page', 50),
        ));
    }

    public function show(string $uuid, RunRepository $runs): JsonResponse
    {
        $run = $runs->find($uuid);

        if ($run === null) {
            return $this->json(['message' => 'That run has aged out of the retention window.'], 404);
        }

        return $this->json(['data' => $run]);
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(Request $request): array
    {
        return [
            'connection' => $request->query('connection'),
            'queue' => $request->query('queue'),
            'job_class' => $request->query('job_class'),
            'status' => $request->query('status'),
            'batch_id' => $request->query('batch_id'),
            'tag' => $request->query('tag'),
            'search' => $request->query('search'),
            'failed' => $request->boolean('failed'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'slower_than' => $request->query('slower_than'),
        ];
    }

    public function options(RunRepository $runs): JsonResponse
    {
        return $this->json([
            'connections' => $runs->distinct('connection'),
            'queues' => $runs->distinct('queue'),
            'job_classes' => $runs->distinct('job_class'),
        ]);
    }
}

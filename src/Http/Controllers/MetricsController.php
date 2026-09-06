<?php

namespace Abhishek\Yardmaster\Http\Controllers;

use Abhishek\Yardmaster\Repositories\MetricsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MetricsController extends Controller
{
    public function __invoke(Request $request, MetricsRepository $metrics): JsonResponse
    {
        [$from, $to] = $this->window($request);

        $filters = array_filter([
            'connection' => $request->query('connection'),
            'queue' => $request->query('queue'),
            'job_class' => $request->query('job_class'),
        ], static fn ($value) => is_string($value) && $value !== '');

        return $this->json([
            'summary' => $metrics->summary($from, $to, $filters),
            'series' => $metrics->series($from, $to, $filters),
            'top_job_classes' => $metrics->topJobClasses($from, $to, $filters),
        ]);
    }

    /**
     * @return array{0: int, 1: int}
     */
    protected function window(Request $request): array
    {
        $to = (int) $request->query('to', (string) time());
        $from = (int) $request->query('from', (string) ($to - 3600));

        // A window that runs backwards, or one spanning years, is a client bug.
        // Clamping is friendlier than a validation error on a dashboard poll.
        if ($from >= $to) {
            $from = $to - 3600;
        }

        return [max($from, $to - (400 * 86400)), $to];
    }
}

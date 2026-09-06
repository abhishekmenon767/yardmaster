<?php

namespace Iocod\Yardmaster\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Iocod\Yardmaster\Repositories\WorkerRepository;

class WorkerController extends Controller
{
    public function __invoke(WorkerRepository $workers): JsonResponse
    {
        return $this->json(['data' => $workers->all()]);
    }
}

<?php

namespace Abhishek\Yardmaster\Http\Controllers;

use Abhishek\Yardmaster\Repositories\WorkerRepository;
use Illuminate\Http\JsonResponse;

class WorkerController extends Controller
{
    public function __invoke(WorkerRepository $workers): JsonResponse
    {
        return $this->json(['data' => $workers->all()]);
    }
}

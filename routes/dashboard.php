<?php

use Illuminate\Support\Facades\Route;
use Iocod\Yardmaster\Http\Controllers\ActionController;
use Iocod\Yardmaster\Http\Controllers\AssetController;
use Iocod\Yardmaster\Http\Controllers\DashboardController;
use Iocod\Yardmaster\Http\Controllers\FailureController;
use Iocod\Yardmaster\Http\Controllers\IssueController;
use Iocod\Yardmaster\Http\Controllers\MetaController;
use Iocod\Yardmaster\Http\Controllers\MetricsController;
use Iocod\Yardmaster\Http\Controllers\QueueController;
use Iocod\Yardmaster\Http\Controllers\RunController;
use Iocod\Yardmaster\Http\Controllers\StreamController;
use Iocod\Yardmaster\Http\Controllers\WorkerController;
use Iocod\Yardmaster\Http\Middleware\Authorize;
use Iocod\Yardmaster\Http\Middleware\EnsureTablesExist;

Route::prefix('api/v1')->middleware(EnsureTablesExist::class)->name('yardmaster.api.')->group(function () {
    Route::get('meta', MetaController::class)->name('meta');

    Route::get('queues', [QueueController::class, 'index'])->name('queues.index');
    Route::get('queues/jobs', [QueueController::class, 'jobs'])->name('queues.jobs');

    Route::get('runs', [RunController::class, 'index'])->name('runs.index');
    Route::get('runs/options', [RunController::class, 'options'])->name('runs.options');
    Route::get('runs/{uuid}', [RunController::class, 'show'])->name('runs.show');

    Route::get('failures', [FailureController::class, 'index'])->name('failures.index');

    Route::get('issues', [IssueController::class, 'index'])->name('issues.index');
    Route::get('issues/{fingerprint}', [IssueController::class, 'show'])->name('issues.show');
    Route::get('workers', WorkerController::class)->name('workers');
    Route::get('metrics', MetricsController::class)->name('metrics');
    Route::get('actions', ActionController::class)->name('actions');
    Route::get('stream', StreamController::class)->name('stream');

    // Everything that changes state sits behind its own gate. Viewing a queue
    // and emptying one are not the same permission.
    Route::middleware(Authorize::class.':manageYardmaster')->group(function () {
        Route::post('queues/pause', [QueueController::class, 'pause'])->name('queues.pause');
        Route::post('queues/resume', [QueueController::class, 'resume'])->name('queues.resume');
        Route::post('queues/purge', [QueueController::class, 'purge'])->name('queues.purge');
        Route::delete('queues/jobs', [QueueController::class, 'forget'])->name('queues.forget');
        Route::post('queues/jobs/promote', [QueueController::class, 'promote'])->name('queues.promote');

        Route::post('issues/{fingerprint}/retry', [IssueController::class, 'retry'])->name('issues.retry');
        Route::post('issues/{fingerprint}/status', [IssueController::class, 'status'])->name('issues.status');

        Route::post('failures/retry', [FailureController::class, 'retry'])->name('failures.retry');
        Route::delete('failures', [FailureController::class, 'forget'])->name('failures.forget');
    });
});

Route::get('yardmaster.js', [AssetController::class, 'js'])->name('yardmaster.assets.js');
Route::get('yardmaster.css', [AssetController::class, 'css'])->name('yardmaster.assets.css');

Route::get('/{view?}', DashboardController::class)
    ->where('view', '(.*)')
    ->name('yardmaster.dashboard');

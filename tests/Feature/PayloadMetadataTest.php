<?php

use Abhishek\Yardmaster\Tests\Fixtures\DispatchingJob;
use Abhishek\Yardmaster\Tests\Fixtures\SucceedingJob;
use Abhishek\Yardmaster\Tests\Fixtures\Widget;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('never stores the serialized command', function () {
    SucceedingJob::dispatch('hello');

    $payload = json_decode(DB::table('yard_runs')->value('payload'), true);

    expect($payload)->toBeArray()
        ->and($payload['data'])->not->toHaveKey('command')
        ->and($payload['data']['commandName'])->toBe(SucceedingJob::class);
});

it('measures wait time from dispatch, not from the worker picking the job up', function () {
    SucceedingJob::dispatch()->onConnection('database');

    usleep(50_000);

    $this->work('database');

    expect((float) DB::table('yard_runs')->value('wait_ms'))->toBeGreaterThan(40.0);
});

it('links a job dispatched inside another job to its parent', function () {
    DispatchingJob::dispatch();

    $parent = DB::table('yard_runs')->where('job_class', DispatchingJob::class)->first();
    $child = DB::table('yard_runs')->where('job_class', SucceedingJob::class)->first();

    expect($child->parent_uuid)->not->toBeNull()
        ->and($child->parent_uuid)->toBe($parent->job_uuid);
});

it('tags a job with the models it carries', function () {
    Schema::create('widgets', function (Blueprint $table) {
        $table->id();
        $table->string('name');
    });

    $widget = Widget::create(['name' => 'press']);

    SucceedingJob::dispatch('with model', $widget);

    $tags = json_decode(DB::table('yard_runs')->value('tags'), true);

    expect($tags)->toContain(Widget::class.':'.$widget->id);
});

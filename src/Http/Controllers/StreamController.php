<?php

namespace Abhishek\Yardmaster\Http\Controllers;

use Abhishek\Yardmaster\Http\StreamPayload;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Server-sent events, deliberately rather than websockets.
 *
 * A dashboard needs one long-lived read connection, which is exactly what SSE
 * is. Choosing it means no Reverb, no Echo and no extra infrastructure to run
 * before the dashboard shows a live number — and browsers reconnect on their
 * own when it drops.
 */
class StreamController extends Controller
{
    public function __invoke(Request $request, StreamPayload $payload): StreamedResponse
    {
        $interval = max(1, min(30, (int) $request->query('interval', 3)));
        $duration = max(5, min(300, (int) $request->query('duration', 60)));

        $response = new StreamedResponse(function () use ($payload, $interval, $duration) {
            $deadline = microtime(true) + $duration;

            while (microtime(true) < $deadline) {
                echo 'event: tick'.PHP_EOL;
                echo 'data: '.json_encode($payload->build()).PHP_EOL.PHP_EOL;

                if (ob_get_level() > 0) {
                    ob_flush();
                }

                flush();

                // The stream closes itself rather than living forever. A PHP-FPM
                // worker held open indefinitely by an abandoned browser tab is
                // how a dashboard takes down the application it is watching.
                if (connection_aborted()) {
                    break;
                }

                sleep($interval);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, private');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }
}

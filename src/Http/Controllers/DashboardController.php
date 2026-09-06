<?php

namespace Abhishek\Yardmaster\Http\Controllers;

use Abhishek\Yardmaster\Support\Cast;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;

/**
 * Serves the single-page app shell.
 *
 * Every path under the dashboard prefix lands here so the client router owns
 * navigation; the server only needs to hand over the same shell each time.
 */
class DashboardController extends Controller
{
    public function __invoke(Config $config, ViewFactory $views): View
    {
        $assets = __DIR__.'/../../../dist/yardmaster.js';

        return $views->make('yardmaster::dashboard', [
            'basePath' => '/'.trim(Cast::string($config->get('yardmaster.dashboard.path', 'yardmaster')), '/'),
            // Cache-bust on the built asset itself, so an upgrade invalidates
            // the immutable cache headers without anyone remembering to.
            'version' => is_file($assets) ? (string) filemtime($assets) : 'dev',
        ]);
    }
}

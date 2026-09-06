<?php

namespace Abhishek\Yardmaster\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the compiled dashboard assets from inside the package.
 *
 * Served rather than inlined into the page on purpose: an inline <script> needs
 * a CSP exception, and an application careful enough to run a strict policy
 * should not have to loosen it to install a queue dashboard.
 */
class AssetController extends Controller
{
    public function js(Request $request): Response
    {
        return $this->asset($request, 'yardmaster.js', 'application/javascript');
    }

    public function css(Request $request): Response
    {
        return $this->asset($request, 'yardmaster.css', 'text/css');
    }

    protected function asset(Request $request, string $file, string $type): Response
    {
        $path = __DIR__.'/../../../dist/'.$file;

        if (! is_file($path)) {
            return new Response('', 404);
        }

        $response = new BinaryFileResponse($path, 200, [
            'Content-Type' => $type,
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);

        $response->setAutoLastModified();
        $response->setAutoEtag();
        $response->isNotModified($request);

        return $response;
    }
}

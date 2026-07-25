<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ApiReferenceController extends Controller
{
    /**
     * Render the current API_REFERENCE.md as a styled HTML page. Read fresh
     * from disk on every request, so any edit is reflected immediately.
     */
    public function html(): Response
    {
        $html = Str::markdown($this->readReference(), [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        return response()->view('docs.api-reference', ['html' => $html]);
    }

    /**
     * Serve the raw markdown (for curl / editor import / OpenAPI tooling).
     */
    public function raw(): Response
    {
        return response($this->readReference(), 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
        ]);
    }

    private function readReference(): string
    {
        $path = base_path('API_REFERENCE.md');

        if (! File::exists($path)) {
            throw new NotFoundHttpException('API reference not found.');
        }

        return File::get($path);
    }
}

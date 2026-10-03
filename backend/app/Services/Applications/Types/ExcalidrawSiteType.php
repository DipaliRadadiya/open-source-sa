<?php

namespace App\Services\Applications\Types;

/**
 * Excalidraw — a hand-drawn-style whiteboard.
 *
 * **No volumes, no database, no environment.** The image's final stage is an
 * nginx serving a static build, and a drawing lives in the browser's own
 * storage, so there is nothing on the server to persist and nothing to back up.
 * The smallest app in this catalog by some distance.
 *
 * **What is NOT self-hosted, and why the tagline says so.** The published image
 * is a static bundle with its configuration compiled in, so these cannot be
 * pointed elsewhere by an environment variable the way every other app here is
 * configured:
 *
 *   VITE_APP_WS_SERVER_URL      https://oss-collab.excalidraw.com
 *   VITE_APP_BACKEND_V2_POST_URL https://json.excalidraw.com/api/v2/post/
 *   VITE_APP_LIBRARY_URL        https://libraries.excalidraw.com
 *
 * Drawing is local. **"Share link" uploads the drawing to excalidraw.com and
 * "Live collaboration" routes through their socket server** — on a box somebody
 * installed this on precisely to keep their data. Idle use never calls out, so
 * the app is worth shipping; the tagline is what stops the assumption.
 *
 * Making those local needs `excalidraw-room`, `excalidraw-storage-backend` AND a
 * rebuilt frontend pointing at them. A one-click cannot rebuild an image, and
 * `excalidraw-room`'s own image has not been published since 2023 — so that is a
 * decision about publishing our own image, not a missing environment variable.
 */
class ExcalidrawSiteType extends AbstractDockerAppType
{
    public function name(): string
    {
        return 'excalidraw';
    }

    public function category(): string
    {
        return 'productivity';
    }

    public function icon(): string
    {
        return 'excalidraw';
    }

    public function containerPort(): int
    {
        return 80;
    }

    /** @return array<string, string> */
    public function volumeRoles(): array
    {
        return [];
    }
}

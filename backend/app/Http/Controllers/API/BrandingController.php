<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Central\CentralBranding;
use Illuminate\Http\JsonResponse;

class BrandingController extends Controller
{
    /**
     * The panel's branding, Central's where Central publishes it (2026-10-20).
     *
     * Same shape as before, so nothing that reads it has to change: Central's
     * name, logo, icon, favicon and colour replace the panel's own one by one,
     * and only where Central has a usable value. Central has no dark-mode logo
     * or icon, so `logo_dark` / `icon_dark` stay the panel's — unless Central
     * set a logo, which then stands in for both rather than pairing a
     * reseller's light logo with ours in dark mode.
     *
     * Added, for screens that want them: `tag_line`, `links`,
     * `display_message`, and `source` (`central` | `panel`).
     */
    public function index(CentralBranding $central): JsonResponse
    {
        $branding = config('branding');
        $fromCentral = $central->fetch();

        if ($fromCentral !== null) {
            foreach (['name', 'logo', 'icon', 'favicon', 'primary_color'] as $key) {
                if (isset($fromCentral[$key])) {
                    $branding[$key] = $fromCentral[$key];
                }
            }

            if (isset($fromCentral['logo'])) {
                $branding['logo_dark'] = $fromCentral['logo'];
            }

            if (isset($fromCentral['icon'])) {
                $branding['icon_dark'] = $fromCentral['icon'];
            }
        }

        return response()->json([
            'branding' => [
                ...$branding,
                'tag_line' => $fromCentral['tag_line'] ?? null,
                'links' => $fromCentral['links'] ?? null,
                'display_message' => $fromCentral['display_message'] ?? null,
                'source' => $fromCentral === null ? 'panel' : 'central',
            ],
        ]);
    }
}

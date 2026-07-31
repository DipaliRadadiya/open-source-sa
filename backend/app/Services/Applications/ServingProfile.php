<?php

namespace App\Services\Applications;

use App\Contracts\SiteType;

/**
 * Decides how an application has to be served.
 *
 * One place, because create and update both need the answer and a disagreement
 * between them is invisible until a site is already live: a directory served
 * for an app that routes in code publishes its source, and a proxy with
 * nothing behind it is a permanent 502.
 *
 * The rendering type is the strongest signal because it is the user saying
 * what they built. A start command is the fallback for applications that have
 * no rendering type at all — the one-click Node installers, which are always
 * a process.
 */
class ServingProfile
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function resolve(?SiteType $type, array $data, ?string $current = null): string
    {
        $rendering = $data['rendering_type'] ?? null;

        if (filled($rendering)) {
            // Static and client-side rendering are the same thing to a web
            // server: a directory of files the browser does the work with.
            // Only server-side rendering has something running to talk to,
            // and PHP is served by the PHP stack as always.
            return match ($rendering) {
                'ssr' => 'node',
                'php' => 'php',
                default => 'static',
            };
        }

        if (array_key_exists('start_command', $data)) {
            return filled($data['start_command'])
                ? 'node'
                : ($type?->servingProfile() ?? $current ?? 'php');
        }

        return $current ?? $type?->servingProfile() ?? 'php';
    }
}

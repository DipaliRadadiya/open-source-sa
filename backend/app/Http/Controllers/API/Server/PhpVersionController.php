<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Php\UpdatePhpIniRequest;
use App\Services\ActivityLogger;
use App\Services\Server\Php\PhpVersionManager;
use Illuminate\Http\JsonResponse;

class PhpVersionController extends Controller
{
    /**
     * Installed PHP versions, detected from the same place the Services list
     * reads — so the two can never disagree.
     */
    public function index(PhpVersionManager $php): JsonResponse
    {
        return response()->json([
            'php_versions' => array_map(fn (string $version) => [
                'version' => $version,
                'service' => "php{$version}-fpm",
                'ini_path' => $php->iniPath($version),
            ], $php->versions()),
        ]);
    }

    /**
     * The raw php.ini for a version, for the editor to load.
     */
    public function showIni(string $version, PhpVersionManager $php): JsonResponse
    {
        return response()->json([
            'php_ini' => [
                'version' => $version,
                'path' => $php->iniPath($version),
                'contents' => $php->readIni($version),
            ],
        ]);
    }

    /**
     * Replace the ini. Backed up, validated, and rolled back if PHP rejects
     * it — a broken ini can stop FPM from starting at all, which would take
     * every site on that version down.
     */
    public function updateIni(string $version, UpdatePhpIniRequest $request, PhpVersionManager $php, ActivityLogger $activityLogger): JsonResponse
    {
        $php->writeIni($version, $request->string('contents')->toString());

        $activityLogger->log('php.ini_updated', properties: ['version' => $version]);

        return response()->json([
            'php_ini' => [
                'version' => $version,
                'path' => $php->iniPath($version),
                'contents' => $php->readIni($version),
            ],
        ]);
    }
}

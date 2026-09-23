<?php

namespace App\Http\Controllers\API\Server\SystemUser;

use App\Http\Controllers\Controller;
use App\Services\Server\SystemUsers\InstalledShells;
use Illuminate\Http\JsonResponse;

/**
 * The shells a system user may be given, with labels rather than paths.
 *
 * The allowlist already existed but lived in a FormRequest constant, so the
 * only way the frontend could learn it was to hardcode the same five strings
 * and hope they stayed in step — and the labels beside them would have been
 * English in all eight locales.
 *
 * Only the ones this server has installed — see InstalledShells.
 */
class ShellCatalogController extends Controller
{
    public function __invoke(InstalledShells $shells): JsonResponse
    {
        return response()->json(['shells' => $shells->catalog()]);
    }
}

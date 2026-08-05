<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\BrowseFilesRequest;
use App\Http\Requests\Server\Application\SaveFileRequest;
use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\FileBrowser;
use App\Services\Server\Applications\PermissionFixer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ApplicationFileController extends Controller
{
    public function fixPermissions(
        Application $application,
        PermissionFixer $fixer,
        ActivityLogger $activity,
    ): JsonResponse {
        $fixer->fix($application);

        $activity->log('application.permissions_fixed', $application, [
            'name' => $application->name,
        ]);

        return response()->json(['fixed' => true]);
    }

    public function index(BrowseFilesRequest $request, Application $application, FileBrowser $files): JsonResponse
    {
        return response()->json([
            'path' => $request->targetPath(),
            'files' => $files->list($application, $request->targetPath()),
        ]);
    }

    public function show(BrowseFilesRequest $request, Application $application, FileBrowser $files): JsonResponse
    {
        $file = $files->read($application, $request->targetPath());

        if ($file['binary']) {
            abort(422, __('errors/application.file_not_text'));
        }

        return response()->json([
            'path' => $request->targetPath(),
            'content' => $file['content'],
            'size' => $file['size'],
        ]);
    }

    public function update(
        SaveFileRequest $request,
        Application $application,
        FileBrowser $files,
        ActivityLogger $activity,
    ): JsonResponse {
        $files->write($application, $request->targetPath(), (string) $request->validated('content'));

        $activity->log('application.file_edited', $application, [
            'name' => $application->name,
            'path' => $request->targetPath(),
        ]);

        return response()->json(['saved' => true]);
    }

    public function download(BrowseFilesRequest $request, Application $application, FileBrowser $files): Response
    {
        $contents = $files->download($application, $request->targetPath());
        $filename = basename($request->targetPath());

        return response($contents, 200, [
            // Never sniffed from the file's own content — an arbitrary
            // downloaded file served as, say, text/html would let its
            // content run as a script in the browser. Force a download
            // instead of letting the browser decide what this is.
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.addslashes($filename).'"',
            'Content-Length' => (string) strlen($contents),
        ]);
    }
}

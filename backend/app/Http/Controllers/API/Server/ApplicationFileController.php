<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\BrowseFilesRequest;
use App\Http\Requests\Server\Application\ChmodFileRequest;
use App\Http\Requests\Server\Application\CompressFileRequest;
use App\Http\Requests\Server\Application\CopyFileRequest;
use App\Http\Requests\Server\Application\CreateDirectoryRequest;
use App\Http\Requests\Server\Application\DeleteFileRequest;
use App\Http\Requests\Server\Application\ExtractFileRequest;
use App\Http\Requests\Server\Application\RenameFileRequest;
use App\Http\Requests\Server\Application\RestoreFileBackupRequest;
use App\Http\Requests\Server\Application\SaveFileRequest;
use App\Http\Requests\Server\Application\SearchFilesRequest;
use App\Http\Requests\Server\Application\UploadFileRequest;
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

    public function search(SearchFilesRequest $request, Application $application, FileBrowser $files): JsonResponse
    {
        $result = $files->search($application, $request->targetPath(), $request->searchQuery());

        return response()->json([
            'path' => $request->targetPath(),
            'query' => $request->searchQuery(),
            'files' => $result['entries'],
            'truncated' => $result['truncated'],
        ]);
    }

    public function folderSize(BrowseFilesRequest $request, Application $application, FileBrowser $files): JsonResponse
    {
        $result = $files->folderSize($application, $request->targetPath());

        return response()->json([
            'path' => $request->targetPath(),
            'size' => $result['size'],
            'size_human' => $result['size_human'],
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
            'backups' => $file['backups'],
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

    public function restoreBackup(
        RestoreFileBackupRequest $request,
        Application $application,
        FileBrowser $files,
        ActivityLogger $activity,
    ): JsonResponse {
        $files->restoreBackup($application, $request->targetPath(), $request->backupName());

        $activity->log('application.file_restored', $application, [
            'name' => $application->name,
            'path' => $request->targetPath(),
        ]);

        return response()->json(['restored' => true]);
    }

    public function upload(
        UploadFileRequest $request,
        Application $application,
        FileBrowser $files,
        ActivityLogger $activity,
    ): JsonResponse {
        $files->upload($application, $request->targetPath(), $request->file('file')->get());

        $activity->log('application.file_uploaded', $application, [
            'name' => $application->name,
            'path' => $request->targetPath(),
        ]);

        return response()->json(['uploaded' => true]);
    }

    public function extract(
        ExtractFileRequest $request,
        Application $application,
        FileBrowser $files,
        ActivityLogger $activity,
    ): JsonResponse {
        $files->extract($application, $request->archivePath(), $request->targetPath());

        $activity->log('application.files_extracted', $application, [
            'name' => $application->name,
            'path' => $request->archivePath(),
            'target' => $request->targetPath(),
        ]);

        return response()->json(['extracted' => true]);
    }

    public function createDirectory(
        CreateDirectoryRequest $request,
        Application $application,
        FileBrowser $files,
        ActivityLogger $activity,
    ): JsonResponse {
        $files->createDirectory($application, $request->targetPath());

        $activity->log('application.directory_created', $application, [
            'name' => $application->name,
            'path' => $request->targetPath(),
        ]);

        return response()->json(['created' => true]);
    }

    public function rename(
        RenameFileRequest $request,
        Application $application,
        FileBrowser $files,
        ActivityLogger $activity,
    ): JsonResponse {
        $files->rename($application, $request->sourcePath(), $request->targetPath());

        $activity->log('application.file_renamed', $application, [
            'name' => $application->name,
            'path' => $request->sourcePath(),
            'target' => $request->targetPath(),
        ]);

        return response()->json(['renamed' => true]);
    }

    public function copy(
        CopyFileRequest $request,
        Application $application,
        FileBrowser $files,
        ActivityLogger $activity,
    ): JsonResponse {
        $files->copy($application, $request->sourcePath(), $request->targetPath());

        $activity->log('application.file_copied', $application, [
            'name' => $application->name,
            'path' => $request->sourcePath(),
            'target' => $request->targetPath(),
        ]);

        return response()->json(['copied' => true]);
    }

    public function compress(
        CompressFileRequest $request,
        Application $application,
        FileBrowser $files,
        ActivityLogger $activity,
    ): JsonResponse {
        $files->compress($application, $request->sourcePath(), $request->targetPath());

        $activity->log('application.files_compressed', $application, [
            'name' => $application->name,
            'path' => $request->sourcePath(),
            'target' => $request->targetPath(),
        ]);

        return response()->json(['compressed' => true]);
    }

    public function chmod(
        ChmodFileRequest $request,
        Application $application,
        FileBrowser $files,
        ActivityLogger $activity,
    ): JsonResponse {
        $files->chmod($application, $request->targetPath(), $request->mode());

        $activity->log('application.file_chmod', $application, [
            'name' => $application->name,
            'path' => $request->targetPath(),
            'mode' => $request->mode(),
        ]);

        return response()->json(['chmoded' => true]);
    }

    public function destroy(
        DeleteFileRequest $request,
        Application $application,
        FileBrowser $files,
        ActivityLogger $activity,
    ): JsonResponse {
        $files->delete($application, $request->targetPath());

        $activity->log('application.file_deleted', $application, [
            'name' => $application->name,
            'path' => $request->targetPath(),
        ]);

        return response()->json(['deleted' => true]);
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

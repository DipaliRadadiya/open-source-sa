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
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        $paths = $request->selectedPaths();

        $result = $request->isBulk()
            ? $files->transferMany($application, $paths, $request->targetDirectory(), move: true)
            : $this->single(fn () => $files->rename($application, $paths[0], $request->targetPath()), $paths[0]);

        $activity->log('application.file_renamed', $application, [
            'name' => $application->name,
            'path' => $paths[0],
            'target' => $request->isBulk() ? $request->targetDirectory() : $request->targetPath(),
            'count' => count($paths),
        ]);

        return response()->json(array_merge(['renamed' => $result['failed'] === []], $result));
    }

    public function copy(
        CopyFileRequest $request,
        Application $application,
        FileBrowser $files,
        ActivityLogger $activity,
    ): JsonResponse {
        $paths = $request->selectedPaths();

        $result = $request->isBulk()
            ? $files->transferMany($application, $paths, $request->targetDirectory(), move: false)
            : $this->single(fn () => $files->copy($application, $paths[0], $request->targetPath()), $paths[0]);

        $activity->log('application.file_copied', $application, [
            'name' => $application->name,
            'path' => $paths[0],
            'target' => $request->isBulk() ? $request->targetDirectory() : $request->targetPath(),
            'count' => count($paths),
        ]);

        return response()->json(array_merge(['copied' => $result['failed'] === []], $result));
    }

    public function compress(
        CompressFileRequest $request,
        Application $application,
        FileBrowser $files,
        ActivityLogger $activity,
    ): JsonResponse {
        $paths = $request->selectedPaths();

        $request->isBulk()
            ? $files->compressMany($application, $paths, $request->targetPath())
            : $files->compress($application, $paths[0], $request->targetPath());

        $activity->log('application.files_compressed', $application, [
            'name' => $application->name,
            'path' => $paths[0],
            'target' => $request->targetPath(),
            'count' => count($paths),
        ]);

        return response()->json(['compressed' => true]);
    }

    /**
     * Wraps a single-path operation in the per-path result shape.
     *
     * The single form still aborts on failure rather than reporting it — a
     * caller who named one file wants the status code, not a list with one
     * entry in it. This only makes the successful response the same shape as
     * the bulk one, so a client can read one field either way.
     *
     * @return array{succeeded: list<string>, failed: list<array{path: string, reason: string}>}
     */
    private function single(callable $operation, string $path): array
    {
        $operation();

        return ['succeeded' => [$path], 'failed' => []];
    }

    public function chmod(
        ChmodFileRequest $request,
        Application $application,
        FileBrowser $files,
        ActivityLogger $activity,
    ): JsonResponse {
        $paths = $request->selectedPaths();

        $result = $request->isBulk()
            ? $files->chmodMany($application, $paths, $request->mode())
            : $this->single(fn () => $files->chmod($application, $paths[0], $request->mode()), $paths[0]);

        $activity->log('application.file_chmod', $application, [
            'name' => $application->name,
            'path' => $paths[0],
            'mode' => $request->mode(),
            'count' => count($paths),
        ]);

        return response()->json(array_merge(['chmoded' => $result['failed'] === []], $result));
    }

    public function destroy(
        DeleteFileRequest $request,
        Application $application,
        FileBrowser $files,
        ActivityLogger $activity,
    ): JsonResponse {
        $paths = $request->selectedPaths();

        $result = $request->isBulk()
            ? $files->deleteMany($application, $paths)
            : $this->single(fn () => $files->delete($application, $paths[0]), $paths[0]);

        // One entry for the whole operation, with the count. N entries would
        // bury every other kind of activity the moment someone clears a cache
        // directory.
        $activity->log('application.file_deleted', $application, [
            'name' => $application->name,
            'path' => $paths[0],
            'count' => count($paths),
        ]);

        return response()->json(array_merge(['deleted' => $result['failed'] === []], $result));
    }

    public function download(BrowseFilesRequest $request, Application $application, FileBrowser $files): StreamedResponse
    {
        $file = $files->download($application, $request->targetPath());

        return response()->stream(function () use ($file): void {
            foreach ($file['chunks'] as $chunk) {
                echo $chunk;

                // Without this the chunks pile up in PHP's own output buffer
                // and the memory saved by streaming the read is spent on the
                // write instead.
                if (ob_get_level() > 0) {
                    ob_flush();
                }

                flush();
            }
        }, 200, [
            // Never sniffed from the file's own content — an arbitrary
            // downloaded file served as, say, text/html would let its
            // content run as a script in the browser. Force a download
            // instead of letting the browser decide what this is.
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => $this->attachment(basename($request->targetPath())),
            'Content-Length' => (string) $file['size'],
            // Chunks must reach the client as they are produced. Without this
            // nginx buffers the whole response before sending a byte, which
            // reinstates on the way out exactly the memory cost the streaming
            // read removed.
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * A Content-Disposition value that survives the filename.
     *
     * `addslashes()` was not enough: it leaves UTF-8 alone (so a non-ASCII
     * name arrives mangled or dropped) and does nothing about a newline,
     * which would end the header and let the rest be read as another one.
     * RFC 6266 answers both — a plain ASCII `filename` for old clients and a
     * percent-encoded `filename*` that everything current prefers.
     */
    private function attachment(string $filename): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $filename) ?? 'download';
        $ascii = str_replace(['\\', '"'], ['\\\\', '\\"'], $ascii);

        return sprintf(
            'attachment; filename="%s"; filename*=UTF-8\'\'%s',
            $ascii,
            rawurlencode($filename),
        );
    }
}

<?php

namespace App\Http\Controllers\API\Server;

use App\Enums\FileArchiveStatus;
use App\Exceptions\Server\Application\FileRefusedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\BrowseFilesRequest;
use App\Http\Requests\Server\Application\ChmodFileRequest;
use App\Http\Requests\Server\Application\CompressFileRequest;
use App\Http\Requests\Server\Application\CopyFileRequest;
use App\Http\Requests\Server\Application\CreateDirectoryRequest;
use App\Http\Requests\Server\Application\DeleteFileRequest;
use App\Http\Requests\Server\Application\EmptyTrashRequest;
use App\Http\Requests\Server\Application\ExtractFileRequest;
use App\Http\Requests\Server\Application\RenameFileRequest;
use App\Http\Requests\Server\Application\RestoreFileBackupRequest;
use App\Http\Requests\Server\Application\RestoreTrashRequest;
use App\Http\Requests\Server\Application\SaveFileRequest;
use App\Http\Requests\Server\Application\SearchFilesRequest;
use App\Http\Requests\Server\Application\UploadFileRequest;
use App\Http\Resources\FileArchiveJobResource;
use App\Models\Application;
use App\Models\FileArchiveJob;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\FileBrowser;
use App\Services\Server\Applications\PermissionFixer;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
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
        $listing = $files->list($application, $request->targetPath(), $request->includeHidden());

        return response()->json([
            'path' => $request->targetPath(),
            'files' => $listing['entries'],
            'hidden_count' => $listing['hidden_count'],
        ]);
    }

    /**
     * How this directory's bytes divide by file type.
     *
     * Separate from the listing: it walks every file underneath, a listing reads one level.
     */
    public function breakdown(BrowseFilesRequest $request, Application $application, FileBrowser $files): JsonResponse
    {
        return response()->json([
            'path' => $request->targetPath(),
            'breakdown' => $files->breakdown($application, $request->targetPath()),
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

    public function folderSizes(BrowseFilesRequest $request, Application $application, FileBrowser $files): JsonResponse
    {
        return response()->json([
            'path' => $request->targetPath(),
            ...$files->folderSizes($application, $request->targetPath(), $request->refresh()),
        ]);
    }

    public function show(BrowseFilesRequest $request, Application $application, FileBrowser $files): JsonResponse
    {
        $file = $files->read($application, $request->targetPath());

        if ($file['binary']) {
            throw FileRefusedException::because('path', 'not_text', 'errors/application.file_not_text');
        }

        return response()->json([
            'path' => $request->targetPath(),
            'content' => $file['content'],
            'size' => $file['size'],
            'version' => $file['version'],
            'backups' => $file['backups'],
        ]);
    }

    public function update(
        SaveFileRequest $request,
        Application $application,
        FileBrowser $files,
        ActivityLogger $activity,
    ): JsonResponse {
        $version = $files->write($application, $request->targetPath(), (string) $request->validated('content'), $request->expectedVersion(), $request->safetyCopy());

        $activity->log('application.file_edited', $application, [
            'name' => $application->name,
            'path' => $request->targetPath(),
            // False when turned off, so the line says "(without a safety copy)".
            'safety_copy' => $request->safetyCopy(),
        ]);

        return response()->json(['saved' => true, 'version' => $version]);
    }

    public function restoreBackup(
        RestoreFileBackupRequest $request,
        Application $application,
        FileBrowser $files,
        ActivityLogger $activity,
    ): JsonResponse {
        $restored = $files->restoreBackup($application, $request->targetPath(), $request->backupName(), $request->safetyCopy());

        $activity->log('application.file_restored', $application, [
            'name' => $application->name,
            'path' => $request->targetPath(),
            'safety_copy' => $request->safetyCopy(),
        ]);

        // Includes the content, so the editor shows what is now on disk.
        return response()->json(['restored' => true, ...$restored]);
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
        $job = $files->extract($application, $request->archivePath(), $request->targetPath());

        $activity->log('application.files_extracted', $application, [
            'name' => $application->name,
            'path' => $request->archivePath(),
            'target' => $request->targetPath(),
        ]);

        return response()->json(
            ['job' => new FileArchiveJobResource($job)],
            SymfonyResponse::HTTP_ACCEPTED,
        );
    }

    /**
     * Archive operations that are running or finished in the last 5 minutes.
     *
     * Recent completions are included so a job does not vanish between two polls.
     */
    public function archiveJobs(Application $application, FileBrowser $files): JsonResponse
    {
        $jobs = FileArchiveJob::query()
            ->where('application_id', $application->id)
            ->where(fn ($q) => $q->inFlight()->orWhere('finished_at', '>=', now()->subMinutes(5)))
            ->latest('id')
            ->limit(20)
            ->get()
            // A killed worker never reaches `failed()` and the row stays `running`;
            // settle it on read.
            ->each(function (FileArchiveJob $job) use ($files) {
                if ($job->isStale()) {
                    $job->update([
                        'status' => FileArchiveStatus::Failed,
                        'reason' => 'worker',
                        'finished_at' => now(),
                    ]);

                    return;
                }

                // Progress of a running compress, read here because the worker is inside one
                // blocking `tar`. Not persisted: it is stale the moment it is read.
                if ($job->status === FileArchiveStatus::Running && $job->operation === 'compress') {
                    $job->size_bytes = $files->archiveSize($job);
                }
            });

        return response()->json(['data' => FileArchiveJobResource::collection($jobs)]);
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

        $job = $request->isBulk()
            ? $files->compressMany($application, $paths, $request->targetPath())
            : $files->compress($application, $paths[0], $request->targetPath());

        $activity->log('application.files_compressed', $application, [
            'name' => $application->name,
            'path' => $paths[0],
            'target' => $request->targetPath(),
            'count' => count($paths),
        ]);

        // 202, not 200: the archive does not exist yet.
        return response()->json(
            ['job' => new FileArchiveJobResource($job)],
            SymfonyResponse::HTTP_ACCEPTED,
        );
    }

    /**
     * Wraps a single-path operation in the per-path result shape.
     *
     * A failure still aborts (status code); only success is shaped like the bulk response.
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

    /**
     * What is recoverable, newest first. Empty is a normal answer.
     */
    public function trash(BrowseFilesRequest $request, Application $application, FileBrowser $files): JsonResponse
    {
        return response()->json([
            ...$files->trashDetails($application),
            'retention_days' => (int) config('server.applications.trash_retention_days', 7),
        ]);
    }

    /**
     * Put one trashed path, or every path in one batch, back where it came from.
     *
     * Batch restores report per-path outcomes.
     */
    public function restoreTrash(
        RestoreTrashRequest $request,
        Application $application,
        FileBrowser $files,
        ActivityLogger $activity,
    ): JsonResponse {
        $path = $request->path();
        $result = $path === null
            ? $files->restoreBatchFromTrash($application, $request->batch())
            : $this->single(fn () => $files->restoreFromTrash($application, $request->batch(), $path), $path);

        if ($result['succeeded'] !== []) {
            $activity->log('application.file_restored', $application, [
                'name' => $application->name,
                'path' => $path ?? $request->batch(),
                'count' => count($result['succeeded']),
            ]);
        }

        return response()->json([
            'restored' => $result['failed'] === [],
            ...$result,
            ...$files->trashDetails($application),
            'retention_days' => (int) config('server.applications.trash_retention_days', 7),
        ]);
    }

    /**
     * Empty the trash, or one batch of it. Unrecoverable by design: it frees the disk space.
     */
    public function emptyTrash(
        EmptyTrashRequest $request,
        Application $application,
        FileBrowser $files,
        ActivityLogger $activity,
    ): JsonResponse {
        $files->emptyTrash($application, $request->batch());

        $activity->log('application.trash_emptied', $application, [
            'name' => $application->name,
            'batch' => $request->batch() ?? '—',
        ]);

        return response()->json(['trash' => $files->trash($application)]);
    }

    public function destroy(
        DeleteFileRequest $request,
        Application $application,
        FileBrowser $files,
        ActivityLogger $activity,
    ): JsonResponse {
        $paths = $request->selectedPaths();

        $permanent = $request->permanent();

        $result = $request->isBulk()
            ? $files->deleteMany($application, $paths, $permanent)
            : $this->single(fn () => $files->delete($application, $paths[0], $permanent), $paths[0]);

        // One entry for the whole operation, with the count.
        $activity->log('application.file_deleted', $application, [
            'name' => $application->name,
            'path' => $paths[0],
            'count' => count($paths),
            'permanent' => $permanent ? 'yes' : 'no',
        ]);

        return response()->json(array_merge(['deleted' => $result['failed'] === []], $result));
    }

    public function download(BrowseFilesRequest $request, Application $application, FileBrowser $files): StreamedResponse
    {
        $file = $files->download($application, $request->targetPath());

        return response()->stream(function () use ($file): void {
            foreach ($file['chunks'] as $chunk) {
                echo $chunk;

                // Without this the chunks pile up in PHP's output buffer.
                if (ob_get_level() > 0) {
                    ob_flush();
                }

                flush();
            }
        }, 200, [
            // Never sniffed from the file's content: a user file served as text/html would
            // run as a script. Force a download.
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => $this->attachment(basename($request->targetPath())),
            'Content-Length' => (string) $file['size'],
            // Without this nginx buffers the whole response before sending a byte.
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * An image, as an image: the one response here a browser may render.
     *
     * Safety is in `FileBrowser::preview()` (type from the file's bytes, SVG refused, size
     * capped) and these headers:
     *
     *  - `nosniff`: the browser cannot reinterpret the type,
     *  - CSP `default-src 'none'` + `sandbox`: a misidentified file executes and loads nothing,
     *  - `inline`: the only difference from `download()`,
     *  - `no-store`: a customer's file must not sit in shared caches.
     */
    public function preview(BrowseFilesRequest $request, Application $application, FileBrowser $files): StreamedResponse
    {
        $file = $files->preview($application, $request->targetPath());

        return response()->stream(function () use ($file): void {
            foreach ($file['chunks'] as $chunk) {
                echo $chunk;

                if (ob_get_level() > 0) {
                    ob_flush();
                }

                flush();
            }
        }, 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => $this->disposition('inline', basename($request->targetPath())),
            'Content-Length' => (string) $file['size'],
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * A Content-Disposition value that survives the filename.
     *
     * `addslashes()` is not enough: it mangles UTF-8 and lets a newline end the header.
     * RFC 6266: an ASCII `filename` for old clients plus a percent-encoded `filename*`.
     */
    private function attachment(string $filename): string
    {
        return $this->disposition('attachment', $filename);
    }

    /**
     * The same header for a response meant to be shown rather than saved.
     */
    private function disposition(string $type, string $filename): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $filename) ?? 'download';
        $ascii = str_replace(['\\', '"'], ['\\\\', '\\"'], $ascii);

        return sprintf(
            '%s; filename="%s"; filename*=UTF-8\'\'%s',
            $type,
            $ascii,
            rawurlencode($filename),
        );
    }
}

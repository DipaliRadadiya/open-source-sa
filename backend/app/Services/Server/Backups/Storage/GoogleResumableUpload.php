<?php

namespace App\Services\Server\Backups\Storage;

use Closure;
use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Log;
use Masbug\Flysystem\StreamableUpload;
use RuntimeException;
use Throwable;

/**
 * Uploads an archive to Drive and survives a failed chunk.
 *
 * `GoogleDriveAdapter::upload()` loops `nextChunk()` with no retry, so any hiccup (a 5xx, a
 * dropped connection, a token blip) ends the upload and Flysystem reports "Not able to write the
 * file" with no exception underneath. This class retries instead.
 *
 * It resumes rather than restarts: a resumable session can be asked how much it holds (`PUT` with
 * `Content-Range: bytes *\/size`, see {@see StreamableUpload::resume()}), so a retry continues from
 * the committed offset instead of re-sending a 100 GB archive.
 *
 * Google's committed offset is the truth, never ours: after a failure, ask and seek to the answer.
 * Guessing writes bytes at the wrong offset, giving a corrupt artefact of the right size.
 */
class GoogleResumableUpload
{
    /**
     * 100 MB, matching the adapter this replaces.
     */
    private const CHUNK_SIZE = 100 * 1024 * 1024;

    /** @var Closure(string, string, string): Drive */
    private Closure $factory;

    /**
     * @param  null|callable(string, string, string): Drive  $factory
     *                                                                 Builds a Drive service. Tests inject a fake so the suite never opens a socket.
     */
    public function __construct(?callable $factory = null)
    {
        $this->factory = $factory !== null
            ? Closure::fromCallable($factory)
            : static function (string $clientId, string $clientSecret, string $refreshToken): Drive {
                $client = new Client;
                $client->setClientId($clientId);
                $client->setClientSecret($clientSecret);
                $client->setScopes([GoogleOauthTokens::SCOPE]);
                $client->setHttpClient(GoogleHttpClient::make());
                $client->fetchAccessTokenWithRefreshToken($refreshToken);

                return new Drive($client);
            };
    }

    /**
     * @param  null|callable(int): void  $onProgress  Cumulative bytes committed.
     */
    public function upload(
        string $clientId,
        string $clientSecret,
        string $refreshToken,
        string $folderId,
        string $key,
        string $path,
        ?callable $onProgress = null,
    ): bool {
        $size = filesize($path);

        if ($size === false || $size <= 0) {
            return false;
        }

        $drive = ($this->factory)($clientId, $clientSecret, $refreshToken);
        $parent = $this->parentFolder($drive, $folderId, $key);

        if ($parent === null) {
            // Returning false (not throwing) lets the caller fall back to `writeStream()`.
            return false;
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        try {
            return $this->send($drive, $parent, basename($key), $handle, $size, $onProgress);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * Drive the chunk loop, asking Google where it got to whenever one fails.
     *
     * @param  resource  $handle
     * @param  null|callable(int): void  $onProgress
     */
    private function send(Drive $drive, string $parent, string $name, $handle, int $size, ?callable $onProgress): bool
    {
        $file = new DriveFile;
        $file->setName($name);
        $file->setParents([$parent]);

        $client = $drive->getClient();
        $client->setDefer(true);

        try {
            $request = $drive->files->create($file, ['fields' => 'id,name,size']);

            $media = new StreamableUpload(
                $client,
                $request,
                'application/gzip',
                Utils::streamFor($handle),
                true,
                self::CHUNK_SIZE,
            );
            $media->setFileSize($size);

            $attempts = (int) config('server.backups.upload_attempts', 5);
            $backoff = (array) config('server.backups.upload_backoff', [5, 15, 30, 60]);
            $failures = 0;
            $status = false;

            while ($status === false) {
                try {
                    $status = $media->nextChunk();
                    // Only consecutive failures count, or a long upload would exhaust its attempts
                    // on unrelated blips hours apart.
                    $failures = 0;
                } catch (Throwable $e) {
                    if (++$failures >= $attempts) {
                        throw new RuntimeException(sprintf(
                            'the upload failed %d times in a row after %s of %s bytes: %s',
                            $failures,
                            number_format($media->getProgress()),
                            number_format($size),
                            $e->getMessage(),
                        ), previous: $e);
                    }

                    Log::channel('server-ops')->warning('resuming a Drive upload after a failed chunk.', [
                        'feature' => 'backup',
                        'op' => 'upload_resume',
                        'attempt' => $failures,
                        'committed' => $media->getProgress(),
                        'total' => $size,
                        'detail' => $e->getMessage(),
                    ]);

                    sleep((int) ($backoff[min($failures - 1, count($backoff) - 1)] ?? 30));

                    // Ask Google, never assume: `resume()` learns how much it actually holds, which
                    // may differ from what we think if the failure happened mid-flight.
                    $status = $media->resume($media->getResumeUri());
                }

                if ($onProgress !== null) {
                    $onProgress($media->getProgress());
                }
            }

            // A DriveFile means Google acknowledged the whole object; anything else is not done.
            if (! $status instanceof DriveFile) {
                throw new RuntimeException(
                    'the upload finished without Google confirming the file',
                );
            }

            if ($onProgress !== null) {
                $onProgress($size);
            }

            return true;
        } finally {
            $client->setDefer(false);
        }
    }

    /**
     * The folder the object key's directory part names, created if absent.
     *
     * Keys are one level deep (`site.example.com/<file>.tar.gz`), so this is a single lookup. It
     * must match the adapter's own layout: readers (download, restore, prune) resolve the key
     * through Flysystem's display paths, and a file parked elsewhere would be unreachable.
     */
    private function parentFolder(Drive $drive, string $folderId, string $key): ?string
    {
        $directory = trim(dirname($key), '/.');

        if ($directory === '') {
            return $folderId !== '' ? $folderId : 'root';
        }

        $root = $folderId !== '' ? $folderId : 'root';

        try {
            $found = $drive->files->listFiles([
                'q' => sprintf(
                    "name = '%s' and '%s' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false",
                    str_replace("'", "\\'", $directory),
                    str_replace("'", "\\'", $root),
                ),
                'fields' => 'files(id)',
                'pageSize' => 1,
            ])->getFiles();

            if ($found !== []) {
                return $found[0]->getId();
            }

            $folder = new DriveFile;
            $folder->setName($directory);
            $folder->setParents([$root]);
            $folder->setMimeType('application/vnd.google-apps.folder');

            return $drive->files->create($folder, ['fields' => 'id'])->getId();
        } catch (Throwable $e) {
            Log::channel('server-ops')->warning('could not resolve the Drive folder for an upload.', [
                'feature' => 'backup',
                'op' => 'upload_folder',
                'detail' => $e->getMessage(),
            ]);

            return null;
        }
    }
}

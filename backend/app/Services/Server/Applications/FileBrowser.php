<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Application\FileOperationException;
use App\Models\Application;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use App\Support\Bytes;
use Illuminate\Support\Carbon;

/**
 * List, view, edit and download a site's own files.
 *
 * Every command runs as **the site's own Linux user**, via `runuser -u`, never
 * as the panel's root. That is the one thing that makes this feature safe: a
 * client-supplied path is validated at the edges (`App\Rules\SafeRelativePath`
 * refuses `..`, absolute paths and anything outside a conservative charset),
 * but validation only catches the tricks someone thought to name. A symlink
 * inside the site pointing outside it is not a `..` in the string — it is
 * exactly the shape of attack path validation cannot see. Running as the
 * site's own user means that attack, if it slipped through, gains nothing:
 * the user already cannot read anything outside what they own.
 *
 * No create, delete, rename, upload or extract — this is the read/view/edit
 * slice on purpose. See this repo's file-manager research for why the rest is
 * deferred rather than an oversight.
 */
class FileBrowser
{
    /** Larger belongs to SFTP, which every panel in the research pointed at
     * for exactly this reason — buffering a big file through a PHP process is
     * how a download becomes a memory exhaustion bug. */
    public const MAX_BYTES = 5 * 1024 * 1024;

    private const BINARY_SNIFF_BYTES = 8192;

    public function __construct(
        private ServerOps $serverOps,
        private ApplicationProvisioner $provisioner,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(Application $application, string $path): array
    {
        $target = $this->resolve($application, $path);
        $this->assertType($application, $target, 'd');

        $result = $this->run($application, [
            'find', $target, '-mindepth', '1', '-maxdepth', '1', '-printf', "%f\t%y\t%s\t%T@\n",
        ], 'list');

        $entries = [];

        foreach (explode("\n", trim($result->output())) as $line) {
            if ($line === '') {
                continue;
            }

            [$name, $type, $size, $mtime] = explode("\t", $line, 4);
            $modifiedAt = Carbon::createFromTimestamp((int) (float) $mtime);

            $entries[] = [
                'name' => $name,
                'type' => match ($type) {
                    'd' => 'dir',
                    'l' => 'symlink',
                    default => 'file',
                },
                'size' => (int) $size,
                'size_human' => Bytes::human((int) $size),
                'modified_at' => $modifiedAt->format('d-m-Y H:i:s'),
                'modified_at_human' => $modifiedAt->diffForHumans(),
            ];
        }

        // Directories first, then alphabetical — how every file manager in
        // the research (and every OS file browser) orders a listing.
        usort($entries, fn (array $a, array $b): int => match (true) {
            $a['type'] === 'dir' && $b['type'] !== 'dir' => -1,
            $a['type'] !== 'dir' && $b['type'] === 'dir' => 1,
            default => strcasecmp($a['name'], $b['name']),
        });

        return $entries;
    }

    /**
     * @return array{content: string, size: int, binary: bool}
     */
    public function read(Application $application, string $path): array
    {
        $target = $this->resolve($application, $path);
        $size = $this->assertType($application, $target, 'f');

        abort_if($size > self::MAX_BYTES, 422, __('errors/application.file_too_large'));

        $content = $this->run($application, ['cat', $target], 'read')->output();

        return [
            'content' => $content,
            'size' => $size,
            'binary' => str_contains(substr($content, 0, self::BINARY_SNIFF_BYTES), "\0"),
        ];
    }

    /** Raw bytes for a download response. Binary is fine here — it is the client's file, not rendered as text. */
    public function download(Application $application, string $path): string
    {
        $target = $this->resolve($application, $path);
        $size = $this->assertType($application, $target, 'f');

        abort_if($size > self::MAX_BYTES, 422, __('errors/application.file_too_large'));

        return $this->run($application, ['cat', $target], 'download')->output();
    }

    public function write(Application $application, string $path, string $content): void
    {
        $target = $this->resolve($application, $path);
        // Editing, not creating: a path that does not already resolve to a
        // regular file is refused, the same distinction "edit" implies
        // everywhere else in the panel.
        $this->assertType($application, $target, 'f');

        $this->run($application, ['tee', $target], 'write', input: $content);
    }

    private function resolve(Application $application, string $path): string
    {
        $root = rtrim($this->provisioner->documentRoot($application), '/');

        return $path === '' ? $root : "{$root}/{$path}";
    }

    /**
     * Confirms the path exists and is the expected type, without treating
     * "does not exist" as a server-operation failure — that is a 404 for the
     * caller, not something needing a support reference.
     */
    private function assertType(Application $application, string $target, string $expected): int
    {
        $result = $this->serverOps->run(
            $this->asUser($application, ['find', $target, '-maxdepth', '0', '-printf', "%y\t%s"]),
            ['feature' => 'application', 'op' => 'file_stat', 'application' => $application->id],
            timeout: 30,
        );

        $output = trim($result->output());

        abort_if($result->failed() || $output === '', 404);

        [$type, $size] = explode("\t", $output, 2);

        abort_if($type !== $expected, 404);

        return (int) $size;
    }

    /**
     * @param  array<int, string>  $command
     */
    private function run(Application $application, array $command, string $op, ?string $input = null): ServerOpsResult
    {
        $result = $this->serverOps->run(
            $this->asUser($application, $command),
            ['feature' => 'application', 'op' => "file_{$op}", 'application' => $application->id],
            timeout: 60,
            input: $input,
        );

        if ($result->failed()) {
            throw new FileOperationException($result->reference, busy: $result->busy, staleLock: $result->staleLock);
        }

        return $result;
    }

    /**
     * @param  array<int, string>  $command
     * @return array<int, string>
     */
    private function asUser(Application $application, array $command): array
    {
        return array_merge(['runuser', '-u', $application->systemUser->username, '--'], $command);
    }
}

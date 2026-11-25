<?php

namespace App\Services\Server;

/**
 * Writes a panel-owned config file through ServerOps.
 *
 * Not `File::put`: a PHP write failure leaks an internal path to the API consumer and leaves
 * nothing in the server-ops log to trace.
 *
 * Content goes over stdin rather than in the command, so a config that
 * happens to contain a password never reaches `ps`.
 */
class ManagedFile
{
    public function __construct(private ServerOps $serverOps) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function put(string $path, string $contents, array $context = []): ServerOpsResult
    {
        return $this->serverOps->run(
            ['tee', $path],
            array_merge($context, ['op' => 'write_file', 'path' => $path]),
            input: $contents,
        );
    }

    /**
     * Read a panel-owned file back, as root.
     *
     * A render says what the file *would* contain; only a read says what is actually serving.
     *
     * Read `ok` before `output()`: an empty string means both "empty file" and "could not be
     * read", and treating the second as the first writes emptiness over a working config.
     * See `ServerOpsResult::$answered`.
     *
     * Contents are not logged on success (ServerOps keeps stdout only on failure or with
     * `log_output`), so a file holding a credential is not copied into the ops log.
     *
     * @param  array<string, mixed>  $context
     */
    public function get(string $path, array $context = []): ServerOpsResult
    {
        return $this->serverOps->run(
            ['cat', $path],
            array_merge($context, ['op' => 'read_file', 'path' => $path]),
        );
    }

    /**
     * Point `link` at `target`, replacing whatever `link` was before.
     *
     * @param  array<string, mixed>  $context
     */
    public function symlink(string $target, string $link, array $context = []): ServerOpsResult
    {
        return $this->serverOps->run(
            // -f: replace an existing link instead of failing.
            ['ln', '-sf', $target, $link],
            array_merge($context, ['op' => 'symlink', 'target' => $target, 'link' => $link]),
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function delete(string $path, array $context = []): ServerOpsResult
    {
        return $this->serverOps->run(
            // -f: removing a file that is already gone succeeds.
            ['rm', '-f', $path],
            array_merge($context, ['op' => 'delete_file', 'path' => $path]),
        );
    }
}

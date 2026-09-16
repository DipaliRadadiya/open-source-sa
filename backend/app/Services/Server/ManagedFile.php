<?php

namespace App\Services\Server;

/**
 * Writes a panel-owned config file through ServerOps.
 *
 * Not `File::put`. Three settings groups used to write straight to
 * /etc with PHP's filesystem functions, which meant a failure escaped as a
 * raw `file_put_contents(/etc/ssh/sshd_config.d/00-panel.conf): Failed to
 * open stream` — an internal path handed to the API consumer, with no
 * reference and nothing in the server-ops log to trace. Every other write on
 * the server side goes through ServerOps; these did not, and only because
 * nobody checked.
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
     * The counterpart `put()` never had, and its absence is why callers that
     * needed the current contents of a file re-rendered them from the database
     * instead — which answers a different question. A render says what the file
     * *would* contain if the panel wrote it now; only a read says what is
     * actually serving. The two agree right up until somebody edits the file by
     * hand, which is exactly the moment the difference matters.
     *
     * Read `ok` before `output()`. An empty string is returned both for a file
     * that is genuinely empty and for one that could not be read at all — a
     * missing path, or a sudo grant that does not cover `cat` — and a caller
     * that treats the second as the first will happily write emptiness over a
     * working config. `ServerOpsResult::$answered` documents the same trap.
     *
     * Contents are not logged on success (ServerOps keeps stdout only on
     * failure, or when a caller opts in with `log_output`), so reading a file
     * that holds a credential does not copy it into the ops log.
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
            // -f so re-applying an unchanged site replaces the link atomically
            // instead of failing because it already exists.
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
            // -f so removing something already gone is a success, which is
            // what "make sure this file is not there" means.
            ['rm', '-f', $path],
            array_merge($context, ['op' => 'delete_file', 'path' => $path]),
        );
    }
}

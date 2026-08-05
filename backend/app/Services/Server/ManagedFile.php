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

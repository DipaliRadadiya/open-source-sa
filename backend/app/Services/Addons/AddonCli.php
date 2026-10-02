<?php

namespace App\Services\Addons;

use App\Exceptions\Addons\AddonException;
use App\Services\Server\ServerOps;
use JsonException;

/**
 * Runs one addon binary and turns what it printed into an answer or an
 * AddonException.
 *
 * Both addons share one console contract (their docs/INTEGRATION.md §3): one
 * JSON document on stdout and exit 0 on success; one JSON error envelope on
 * stderr and exit 1 on failure, with `"code":"licence_required"` when the
 * server has not bought it. The answer is passed through unchanged — the
 * addon's JSON is the contract Central reads, so the panel does not reshape it.
 *
 * Always an argument list, never a string: values a caller sends (a search
 * term, a plugin URL) reach the addon as one argv element each.
 */
abstract class AddonCli
{
    public function __construct(protected ServerOps $serverOps) {}

    /** Machine name, as in routes and AddonRun::$addon. */
    abstract public function name(): string;

    /** Human name, for messages. */
    abstract public function label(): string;

    abstract protected function binary(): string;

    /** Working directory to start the binary in, or null for any. */
    protected function workdir(): ?string
    {
        return null;
    }

    /**
     * Present and executable. Read from the filesystem rather than by running
     * it: `/usr/local/bin` is world-readable, and a check that starts the
     * binary would also ask the licence API.
     */
    public function installed(): bool
    {
        $binary = $this->binary();

        return $binary !== '' && is_file($binary) && is_executable($binary);
    }

    /**
     * The binary's own version line, or null. `--version` is exempt from both
     * addons' licence check.
     */
    public function version(): ?string
    {
        if (! $this->installed()) {
            return null;
        }

        $result = $this->serverOps->run([$this->binary(), '--version'], $this->context('version'), timeout: 15, cwd: $this->workdir());

        $line = trim(strtok($result->output(), "\n") ?: '');

        return $result->failed() || $line === '' ? null : $line;
    }

    /**
     * Run the addon and return its decoded success document.
     *
     * @param  array<int, string>  $arguments
     * @return array<string, mixed>
     *
     * @throws AddonException
     */
    public function run(array $arguments, int $timeout, array $context = []): array
    {
        if (! $this->installed()) {
            throw AddonException::of('addon_not_installed', $this->label());
        }

        $result = $this->serverOps->run(
            [$this->binary(), ...$arguments],
            $this->context($arguments[0] ?? 'run', $context),
            timeout: $timeout,
            cwd: $this->workdir(),
        );

        if ($result->timedOut) {
            throw AddonException::of('addon_timed_out', $this->label());
        }

        $stdout = $this->decode($result->output());

        // A success document, whatever the exit code: wp-toolkit reports a
        // checksum mismatch as an answer on stdout with exit 1, and that is
        // the result, not a failure to get one.
        if ($stdout !== null && ($stdout['status'] ?? null) === 'success') {
            return $stdout;
        }

        $error = $this->decode($result->errorOutput());

        if ($error !== null && ($error['code'] ?? null) === 'licence_required') {
            throw AddonException::of('addon_licence_required', $this->label(), (string) ($error['message'] ?? ''));
        }

        if ($error !== null && ($error['status'] ?? null) === 'error') {
            $details = $error;
            unset($details['status']);

            throw AddonException::of('addon_command_failed', $this->label(), (string) ($error['message'] ?? ''), $details);
        }

        throw AddonException::of('addon_bad_output', $this->label());
    }

    /** @return array<string, mixed>|null */
    private function decode(string $text): ?array
    {
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        try {
            $decoded = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Exactly one document is the contract; a PHP notice printed by a
            // plugin ahead of it is the usual reason it is not. The last line
            // is still the document when that happens.
            $last = trim((string) strrchr("\n".$text, "\n"));

            try {
                $decoded = json_decode($last, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return null;
            }
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** @return array<string, mixed> */
    private function context(string $op, array $extra = []): array
    {
        return array_merge(['feature' => 'addon', 'addon' => $this->name(), 'op' => $op], $extra);
    }
}

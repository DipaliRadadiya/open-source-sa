<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApiErrorLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'occurred_at' => $this['datetime'] ?? null,
            'status' => $this['context']['status'] ?? null,
            'method' => $this['context']['method'] ?? null,
            'route' => $this['context']['route'] ?? null,
            'exception' => $this['context']['exception'] ?? null,
            'message' => $this->summaryMessage(),
            // Where it was thrown and the first frames inside the application.
            // Absent on older entries, which recorded neither.
            'file' => $this['context']['file'] ?? null,
            'trace' => $this['context']['trace'] ?? [],
            'reference' => $this['context']['reference'] ?? null,
            'user_id' => $this['context']['user_id'] ?? $this['context']['actor_id'] ?? null,
            'feature' => $this['context']['feature'] ?? null,
            'operation' => $this['context']['op'] ?? null,
            'exit_code' => $this['context']['exit_code'] ?? null,
            // The command itself. Already redacted where it was written --
            // ServerOps runs every command line through CommandRedactor before
            // logging it -- so this is the same text an operator would read in
            // the server-ops log, without having to open it on the box.
            'command' => $this['context']['command'] ?? null,
            // How long it ran and how many times it was tried. A lock retried
            // three times and a command that died in 40ms are different
            // failures, and the timestamps alone cannot tell them apart.
            'duration_ms' => $this['context']['duration_ms'] ?? null,
            'attempts' => $this['context']['attempts'] ?? null,
            'error' => $this->errorSummary(),
        ];
    }

    /**
     * Channel labels, not descriptions of anything.
     *
     * ServerOps writes every entry under the literal message "server
     * operation", and the API handler under "api.error" — both say which pipe
     * the record came down, and neither says what happened. The thing worth
     * reading is in `context`.
     */
    private const CHANNEL_LABELS = ['server operation', 'api.error'];

    /**
     * What to put at the top of the card.
     *
     * `context.message` first, because that is the deliberate one. Then the
     * record's own message — Monolog's top-level field, which is where
     * everything *except* ServerOps says what went wrong. A restore logs
     * "restore failed" there, matched neither branch before this, and fell
     * through to "Server operation failed." on a card whose every other field
     * was also null: a reference id and nothing else, for a failure that knew
     * exactly what was wrong.
     *
     * The labels are filtered rather than the top-level field ignored, because
     * falling back to "server operation" would be a worse headline than the
     * generic sentence, not a better one.
     */
    private function summaryMessage(): string
    {
        $contextMessage = trim((string) ($this['context']['message'] ?? ''));

        if ($contextMessage !== '') {
            return $contextMessage;
        }

        $message = trim((string) ($this['message'] ?? ''));

        if ($message !== '' && ! in_array($message, self::CHANNEL_LABELS, true)) {
            return $message;
        }

        return 'Server operation failed.';
    }

    private function errorSummary(): ?string
    {
        $context = $this['context'] ?? [];
        $stderr = trim((string) ($context['stderr'] ?? ''));
        $stdout = trim((string) ($context['stdout'] ?? ''));

        // `detail` is where anything that is not a shell command explains
        // itself — a restore step, a storage driver, a queued job. Only
        // consulted when there is no command output, so a failing command
        // still shows what it actually printed.
        //
        // Without this, a restore that knew exactly what was wrong ("the
        // artefact backups/…/x.tar.gz is not on the destination") reported it
        // to the server-ops log and showed the operator an empty card. The
        // cause existed, in writing, one file away from the screen asking for
        // it.
        $detail = trim((string) ($context['detail'] ?? ''));

        $error = match (true) {
            $stderr !== '' => $stderr,
            $stdout !== '' => $stdout,
            default => $detail,
        };

        return $error !== '' ? $this->redactedSummary($error) : null;
    }

    /**
     * The TAIL of the output, not the head.
     *
     * `Str::limit()` keeps the first N characters, which is the wrong end of a
     * failed command. ServerOps already settled this for stdout -- "the tail
     * rather than the head, because a command that printed progress before
     * dying puts the reason last" -- but stderr never went through it, and
     * this resource prefers stderr. So the one stream most likely to be noisy
     * was the one bounded from the wrong end.
     *
     * What that cost, concretely: `npm install n8n` writes ~15,000 lines of
     * peer-dependency warnings to stderr and puts the actual failure last. The
     * screen showed 1,000 characters of `@browserbasehq/stagehand` noise and
     * discarded `gyp ERR! stack Error: not found: make` -- so a missing
     * build-essential read as a dependency-resolution problem, and finding it
     * meant opening a 1 MB npm debug log on the server.
     *
     * The limit is 4000, not 1000, and that is measured rather than tidied:
     * against the real log from that failure, the last 1000 characters still
     * do not contain "not found: make" and the last 4000 do. Tail-keeping
     * alone would have looked like a fix and hidden the same cause. Sharing
     * ServerOps' key keeps the two ends of the same pipe in step.
     */
    private function redactedSummary(string $error): string
    {
        $patterns = [
            '#(https?://)[^/\s:@]+:[^/\s@]+@#i' => '$1***:***@',
            '#\b(authorization|bearer|token|password|passwd|secret|api[_-]?key)\b(\s*[:=]\s*|\s+)(\S+)#i' => '$1$2***',
            '#\b(gh[pousr]_|glpat-|npm_)[A-Za-z0-9_\-]{8,}#' => '$1***',
        ];

        $redacted = trim(preg_replace(array_keys($patterns), array_values($patterns), $error) ?? $error);

        $limit = max(0, (int) config('server.log_output_limit', 4000));

        return mb_strlen($redacted) > $limit
            ? '…'.mb_substr($redacted, -$limit)
            : $redacted;
    }
}

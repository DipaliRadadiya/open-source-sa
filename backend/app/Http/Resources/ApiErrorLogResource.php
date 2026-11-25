<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

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
            // Where it was thrown and the first application frames; absent on older entries.
            'file' => $this['context']['file'] ?? null,
            'trace' => $this['context']['trace'] ?? [],
            'reference' => $this['context']['reference'] ?? null,
            'user_id' => $this['context']['user_id'] ?? $this['context']['actor_id'] ?? null,
            'feature' => $this['context']['feature'] ?? null,
            'feature_label' => $this->label('features', $this['context']['feature'] ?? null),
            'operation' => $this['context']['op'] ?? null,
            'operation_label' => $this->label('operations', $this['context']['op'] ?? null),
            'exit_code' => $this['context']['exit_code'] ?? null,
            // Already redacted where it was written (ServerOps runs it through CommandRedactor).
            'command' => $this['context']['command'] ?? null,
            // Duration and attempt count tell a retried lock from a command that died fast.
            'duration_ms' => $this['context']['duration_ms'] ?? null,
            'attempts' => $this['context']['attempts'] ?? null,
            'error' => $this->errorSummary(),
        ];
    }

    /**
     * A readable name for a `feature` or `op` identifier, in the viewer's language.
     *
     * Dotted ops (`git.clone`) are nested in the language file, so a name that
     * lands on a group or on nothing falls back to the identifier spelled out.
     */
    private function label(string $group, mixed $name): ?string
    {
        if (! is_string($name) || $name === '') {
            return null;
        }

        $key = "error_log.{$group}.{$name}";
        $label = trans($key);

        return is_string($label) && $label !== $key
            ? $label
            : Str::ucfirst(str_replace(['_', '.', '-'], ' ', $name));
    }

    /** Channel labels, not descriptions: ServerOps and the API handler write these as message. */
    private const CHANNEL_LABELS = ['server operation', 'api.error'];

    /**
     * What to put at the top of the card.
     *
     * `context.message` first (the deliberate one), then Monolog's top-level message, where
     * everything except ServerOps says what went wrong. Channel labels are filtered out: they
     * make a worse headline than the generic sentence.
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

        // `detail` is where anything that is not a shell command explains itself (a restore step,
        // a storage driver, a queued job). Used only when there is no command output.
        $detail = trim((string) ($context['detail'] ?? ''));

        $error = match (true) {
            $stderr !== '' => $stderr,
            $stdout !== '' => $stdout,
            default => $detail,
        };

        return $error !== '' ? $this->redactedSummary($error) : null;
    }

    /**
     * Redacts secrets and keeps the TAIL of the output, not the head.
     *
     * A failed command prints its reason last (npm writes thousands of warning lines before the
     * real error). The limit is 4000 characters, shared with ServerOps' `server.log_output_limit`:
     * 1000 was too short to reach the cause.
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

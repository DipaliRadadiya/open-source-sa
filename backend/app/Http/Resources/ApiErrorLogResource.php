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
            'message' => $this['context']['message'] ?? 'Server operation failed.',
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

    private function errorSummary(): ?string
    {
        $context = $this['context'] ?? [];
        $stderr = trim((string) ($context['stderr'] ?? ''));
        $stdout = trim((string) ($context['stdout'] ?? ''));
        $error = $stderr !== '' ? $stderr : $stdout;

        return $error !== '' ? $this->redactedSummary($error) : null;
    }

    private function redactedSummary(string $error): string
    {
        $patterns = [
            '#(https?://)[^/\s:@]+:[^/\s@]+@#i' => '$1***:***@',
            '#\b(authorization|bearer|token|password|passwd|secret|api[_-]?key)\b(\s*[:=]\s*|\s+)(\S+)#i' => '$1$2***',
            '#\b(gh[pousr]_|glpat-|npm_)[A-Za-z0-9_\-]{8,}#' => '$1***',
        ];

        $redacted = preg_replace(array_keys($patterns), array_values($patterns), $error) ?? $error;

        return Str::limit(trim($redacted), 1000);
    }
}

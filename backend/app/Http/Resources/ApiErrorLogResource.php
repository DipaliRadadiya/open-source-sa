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
            'reference' => $this['context']['reference'] ?? null,
            'user_id' => $this['context']['user_id'] ?? $this['context']['actor_id'] ?? null,
            'feature' => $this['context']['feature'] ?? null,
            'operation' => $this['context']['op'] ?? null,
            'exit_code' => $this['context']['exit_code'] ?? null,
            'error' => isset($this['context']['stderr'])
                ? $this->redactedSummary((string) $this['context']['stderr'])
                : null,
        ];
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

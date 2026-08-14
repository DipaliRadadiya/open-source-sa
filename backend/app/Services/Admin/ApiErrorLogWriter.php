<?php

namespace App\Services\Admin;

use App\Exceptions\Admin\AdminOperationException;
use App\Exceptions\FeatureException;
use App\Exceptions\Server\ServerOperationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ApiErrorLogWriter
{
    public function record(Throwable $exception, Request $request): void
    {
        if (! $request->is('api/*') || $this->status($exception) < 500) {
            return;
        }

        try {
            Log::channel('api-errors')->error('api.error', [
                'reference' => (string) Str::uuid(),
                'status' => $this->status($exception),
                'method' => $request->method(),
                'route' => $request->route()?->uri() ?? $request->path(),
                'exception' => $exception::class,
                'message' => 'Unexpected API error.',
                'user_id' => $request->user()?->id,
            ]);
        } catch (Throwable) {
            // Observability must never replace the original API failure.
        }
    }

    private function status(Throwable $exception): int
    {
        return match (true) {
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            $exception instanceof FeatureException => $exception->status,
            $exception instanceof AdminOperationException => $exception->status(),
            $exception instanceof ServerOperationException && $exception->busy && ! $exception->staleLock => 503,
            default => 500,
        };
    }
}

<?php

namespace App\Services\Admin;

use App\Exceptions\Admin\AdminOperationException;
use App\Exceptions\FeatureException;
use App\Exceptions\Server\ServerOperationException;
use App\Support\CommandRedactor;
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
            Log::channel('server-ops')->error('api.error', [
                'reference' => (string) Str::uuid(),
                'status' => $this->status($exception),
                'method' => $request->method(),
                'route' => $request->route()?->uri() ?? $request->path(),
                'exception' => $exception::class,
                // The exception's own message, not a placeholder.
                //
                // This screen exists to identify an error, and it was
                // recording the same seven words for every one of them: an
                // error log that says "Unexpected API error." is a count of
                // failures, not a diagnosis. Finding out what a 500 actually
                // was meant reading storage/logs on the server, which is the
                // thing the screen was built to avoid.
                'message' => $this->summarise($exception),
                // Where it was thrown, relative to the install: enough to open
                // the file, without publishing the deployment path.
                'file' => $this->relative($exception->getFile()).':'.$exception->getLine(),
                // The first few frames inside the application. Vendor frames
                // are dropped because the top of a Laravel stack is always the
                // same handler chain and says nothing about this failure.
                'trace' => $this->appFrames($exception),
                'user_id' => $request->user()?->id,
            ]);
        } catch (Throwable) {
            // Observability must never replace the original API failure.
        }
    }

    /**
     * The message, redacted and bounded.
     *
     * Redacted through the same rules the server-ops command lines are: an
     * exception message can carry a connection string or a token, and this log
     * is read more widely than the file it came from. Bounded because a
     * message can be a whole SQL statement.
     */
    private function summarise(Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        // An empty message is common for HTTP exceptions thrown by abort();
        // the class is then the only thing worth saying.
        return $message === ''
            ? $exception::class
            : Str::limit(CommandRedactor::line($message), 500);
    }

    /**
     * @return array<int, string>
     */
    private function appFrames(Throwable $exception): array
    {
        $frames = [];

        foreach ($exception->getTrace() as $frame) {
            $file = $frame['file'] ?? null;

            if ($file === null || str_contains($file, '/vendor/')) {
                continue;
            }

            $frames[] = $this->relative($file).':'.($frame['line'] ?? 0);

            if (count($frames) === 5) {
                break;
            }
        }

        return $frames;
    }

    private function relative(string $path): string
    {
        $base = rtrim(base_path(), '/').'/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
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

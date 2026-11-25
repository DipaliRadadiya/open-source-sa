<?php

namespace App\Services\Admin;

use App\Exceptions\Admin\AdminOperationException;
use App\Exceptions\FeatureException;
use App\Exceptions\Server\ServerOperationException;
use App\Support\CommandRedactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ApiErrorLogWriter
{
    public function record(Throwable $exception, Request $request): void
    {
        $status = $this->status($exception, $request);

        if (! $request->is('api/*') || $status < 500) {
            return;
        }

        try {
            Log::channel('server-ops')->error('api.error', [
                // The reference the user was shown, when the exception carries one, so a lookup
                // finds the right entry.
                'reference' => $this->reference($exception),
                'status' => $status,
                'method' => $request->method(),
                'route' => $request->route()?->uri() ?? $request->path(),
                'exception' => $exception::class,
                // The exception's own message, not a placeholder: the screen must identify the
                // error without anyone reading storage/logs on the server.
                'message' => $this->summarise($exception),
                // Where it was thrown, relative to the install (no deployment path).
                'file' => $this->relative($exception->getFile()).':'.$exception->getLine(),
                // The first few application frames; vendor frames are the same handler chain.
                'trace' => $this->appFrames($exception),
                'user_id' => $request->user()?->id,
            ]);
        } catch (Throwable) {
            // Observability must never replace the original API failure.
        }
    }

    /**
     * A queued job or a scheduled task that failed, on the same screen as the API errors.
     *
     * Same channel and shape, its own `source`: laravel.log is not read by the Error Log screen.
     */
    public function recordBackground(string $source, string $name, ?Throwable $exception = null, ?int $exitCode = null): string
    {
        $reference = $exception !== null ? $this->reference($exception) : (string) Str::uuid();

        try {
            Log::channel('server-ops')->error($source, array_filter([
                'reference' => $reference,
                // The fields the Error Log screen shows: a headline, what ran, and why it stopped.
                'message' => $source === 'job.failed' ? 'A background job failed.' : 'A scheduled task failed.',
                'feature' => $source === 'job.failed' ? 'queue' : 'schedule',
                'op' => $source,
                'command' => $name,
                'exit_code' => $exitCode,
                'exception' => $exception !== null ? $exception::class : null,
                'detail' => $exception !== null ? $this->summarise($exception) : null,
                'file' => $exception !== null ? $this->relative($exception->getFile()).':'.$exception->getLine() : null,
                'trace' => $exception !== null ? $this->appFrames($exception) : null,
            ], fn ($value) => $value !== null));
        } catch (Throwable) {
            // Observability must never replace the original failure.
        }

        return $reference;
    }

    private function reference(Throwable $exception): string
    {
        $reference = property_exists($exception, 'reference') ? ($exception->reference ?? null) : null;

        return is_string($reference) && $reference !== '' ? $reference : (string) Str::uuid();
    }

    /**
     * The message, redacted and bounded.
     *
     * Redacted like the server-ops command lines: a message can carry a connection string or a
     * token, and this log is read more widely than the file it came from. Bounded because a
     * message can be a whole SQL statement.
     */
    private function summarise(Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        // Common for HTTP exceptions thrown by abort(); the class is then all there is to say.
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

    /**
     * The status the user was actually sent.
     *
     * An exception that renders its own response is asked directly (a Fail2ban "this would lock
     * you out" is a 422, not a 500 crash). Rendering only builds the JSON, so asking is free and
     * cannot disagree with what the user saw.
     */
    private function status(Throwable $exception, Request $request): int
    {
        if (method_exists($exception, 'render')) {
            try {
                $response = $exception->render($request);

                if ($response instanceof SymfonyResponse) {
                    return $response->getStatusCode();
                }
            } catch (Throwable) {
                // Fall through to the class-based answer below.
            }
        }

        return match (true) {
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            $exception instanceof FeatureException => $exception->status,
            $exception instanceof AdminOperationException => $exception->status(),
            $exception instanceof ServerOperationException && $exception->busy && ! $exception->staleLock => 503,
            default => 500,
        };
    }
}

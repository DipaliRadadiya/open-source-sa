<?php

namespace App\Services\Server\Doctor;

use App\Contracts\DoctorCheck;
use Throwable;

/**
 * Runs every self-check and reports whether this installation actually works.
 *
 * A green test suite cannot answer "is the panel functional on this box?": it fakes
 * every process, so it passes even where nothing the panel does is permitted.
 */
class Doctor
{
    /** @var list<DoctorCheck> */
    private array $checks;

    public function __construct()
    {
        $this->checks = array_map(
            fn (string $class): DoctorCheck => app($class),
            (array) config('server.doctor.checks', []),
        );
    }

    /**
     * @return array{
     *     healthy: bool,
     *     passed: int,
     *     failed: int,
     *     warnings: int,
     *     checks: list<array{key: string, title: string, status: string, detail: string|null, fix: string|null}>
     * }
     */
    public function run(): array
    {
        $results = [];

        foreach ($this->checks as $check) {
            try {
                $outcome = $check->run();
            } catch (Throwable $e) {
                // A check that throws is itself a finding, not a reason to abandon the run.
                $outcome = [
                    'status' => 'fail',
                    'detail' => __('doctor.details.check_errored', ['error' => $e->getMessage()]),
                    'fix' => null,
                ];
            }

            $results[] = [
                'key' => $check->key(),
                'title' => __('doctor.checks.'.$check->key()),
                'status' => $outcome['status'],
                'detail' => $outcome['detail'],
                // Resolved here so the CLI and the API share the same translated advice.
                'fix' => $outcome['fix'] === null ? null : __($outcome['fix']),
            ];
        }

        $failed = count(array_filter($results, fn (array $r): bool => $r['status'] === 'fail'));

        return [
            // Warnings do not make an installation unhealthy.
            'healthy' => $failed === 0,
            'passed' => count(array_filter($results, fn (array $r): bool => $r['status'] === 'pass')),
            'failed' => $failed,
            'warnings' => count(array_filter($results, fn (array $r): bool => $r['status'] === 'warn')),
            'checks' => $results,
        ];
    }
}

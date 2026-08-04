<?php

namespace App\Services\Server\Doctor;

use App\Contracts\DoctorCheck;
use Throwable;

/**
 * Runs every self-check and reports whether this installation actually works.
 *
 * The point is to answer "is the panel functional on this box?" without the
 * operator having to try each feature and read logs. It exists because a green
 * test suite cannot answer that question: the suite fakes every process, so it
 * passes identically on a server where nothing the panel does is permitted.
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
                // A check that throws is itself a finding, not a reason to
                // abandon the run — the operator still wants the other twelve
                // answers, and "this check broke" is useful information.
                $outcome = [
                    'status' => 'fail',
                    'detail' => 'check errored: '.$e->getMessage(),
                    'fix' => null,
                ];
            }

            $results[] = [
                'key' => $check->key(),
                'title' => __('doctor.checks.'.$check->key()),
                'status' => $outcome['status'],
                'detail' => $outcome['detail'],
                // Resolved here so both the CLI and the API get the same
                // translated advice without either building it themselves.
                'fix' => $outcome['fix'] === null ? null : __($outcome['fix']),
            ];
        }

        $failed = count(array_filter($results, fn (array $r): bool => $r['status'] === 'fail'));

        return [
            // Warnings do not make an installation unhealthy. A version skew
            // right after an update is worth showing and not worth blocking on.
            'healthy' => $failed === 0,
            'passed' => count(array_filter($results, fn (array $r): bool => $r['status'] === 'pass')),
            'failed' => $failed,
            'warnings' => count(array_filter($results, fn (array $r): bool => $r['status'] === 'warn')),
            'checks' => $results,
        ];
    }
}

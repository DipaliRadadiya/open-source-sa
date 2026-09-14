<?php

namespace App\Http\Resources;

use App\Services\Server\Certificates\AcmeReachabilityCheck;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A dry run's verdict, rendered for whoever is asking.
 *
 * Wraps a plain array rather than a model, because a dry run is deliberately
 * not a row — see `CertificateDryRunStore`. It still goes through a Resource
 * for the reason every other response here does: the stored shape is codes,
 * and the sentences are built here, in the request's locale, instead of
 * whatever locale the queue worker happened to boot with.
 *
 * @property array<string, mixed> $resource
 */
class CertificateDryRunResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $state = $this->resource;
        $reachability = app(AcmeReachabilityCheck::class);

        return [
            'status' => $state['status'],
            'stage' => $state['stage'],

            // Per domain, because each failure is a different fix and a single
            // verdict for the site would hide that two of three names are fine.
            'domains' => array_map(fn (array $result) => [
                'domain' => $result['domain'],
                'ok' => $result['ok'],
                'reason' => $result['reason'],
                'resolved_ip' => $result['resolved_ip'] ?? null,
                'message' => $reachability->describe($result),
            ], $state['domains'] ?? []),

            // Set only when the CA stage is what failed — the per-domain list
            // above already explains a reachability failure, and repeating a
            // summary over it would say "something is wrong" twice without
            // adding the thing to do about it.
            'reason' => $state['reason'] ?? null,
            'message' => $state['reason'] === null
                ? null
                : __('certificate.failed.'.$state['reason']),
            'reference' => $state['reference'] ?? null,

            'started_at' => $state['started_at'] ?? null,
            'finished_at' => $state['finished_at'] ?? null,
        ];
    }
}

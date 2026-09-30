<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Application\RemoveCertificate;
use App\Actions\Server\Application\RequestCertificate;
use App\Actions\Server\Application\SetForceHttps;
use App\Actions\Server\Application\StartCertificateDryRun;
use App\Actions\Server\Application\UploadCertificate;
use App\Enums\CertificateType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\StoreCertificateRequest;
use App\Http\Requests\Server\Application\UpdateForceHttpsRequest;
use App\Http\Resources\CertificateDryRunResource;
use App\Http\Resources\CertificateResource;
use App\Models\Application;
use App\Services\Server\Certificates\CertificateDryRunStore;
use App\Services\Server\Certificates\CertificateOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CertificateController extends Controller
{
    /**
     * `null` rather than a 404 when there is no certificate: "this site has no
     * certificate" is a normal state the screen has to render, not an error.
     */
    public function show(Application $application, CertificateOptions $options): JsonResponse
    {
        $certificate = $application->certificate;

        return response()->json([
            'certificate' => $certificate === null
                ? null
                : CertificateResource::make($certificate)->resolve(),
            // What this site can actually be given, so the screen offers the
            // option that works instead of the one that will fail. A test or
            // internal domain has always been able to take a self-signed
            // certificate; nothing said so.
            'available_types' => $options->for($application),
        ]);
    }

    /**
     * Issuing is queued and returns 202; uploading is synchronous and returns
     * 201. The difference is real rather than cosmetic — ACME involves a round
     * trip back to this box and routinely outlasts the request, while writing
     * two files does not.
     */
    public function store(Application $application, StoreCertificateRequest $request, RequestCertificate $issue, UploadCertificate $upload): JsonResponse
    {
        $type = CertificateType::from($request->validated('type'));

        if ($type === CertificateType::Custom) {
            return response()->json([
                'certificate' => CertificateResource::make(
                    $upload->execute($application, $request->validated())
                )->resolve(),
            ], 201);
        }

        return response()->json([
            'certificate' => CertificateResource::make(
                $issue->execute($application, $type, (bool) $request->validated('force', false))
            )->resolve(),
        ], 202);
    }

    /**
     * Rehearse an issuance. 202 and a queued job, like `store()` and for the
     * same reason: the second half of a dry run is a round trip to Let's
     * Encrypt's staging server, which outlasts the request.
     *
     * @throws ValidationException
     */
    public function dryRun(Application $application, StartCertificateDryRun $action): JsonResponse
    {
        return response()->json([
            'dry_run' => CertificateDryRunResource::make($action->execute($application))->resolve(),
        ], 202);
    }

    /**
     * Polled while the run is in flight. `null` rather than a 404 when there
     * has never been one: "this site has not been checked" is a normal state
     * the dialog has to render, not an error.
     */
    public function dryRunStatus(Application $application, CertificateDryRunStore $store): JsonResponse
    {
        $state = $store->get($application->id);

        return response()->json([
            'dry_run' => $state === null
                ? null
                : CertificateDryRunResource::make($state)->resolve(),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function forceHttps(Application $application, UpdateForceHttpsRequest $request, SetForceHttps $action): JsonResponse
    {
        $validated = $request->validated();

        $certificate = $application->certificate;

        if ($certificate === null) {
            throw ValidationException::withMessages([
                'force_https' => [__('errors/certificate.force_https_without_certificate')],
            ]);
        }

        return response()->json([
            'certificate' => CertificateResource::make(
                $action->execute($certificate, (bool) $validated['force_https'])
            )->resolve(),
        ]);
    }

    public function destroy(Application $application, RemoveCertificate $action): JsonResponse
    {
        $certificate = $application->certificate;

        if ($certificate !== null) {
            $action->execute($certificate);
        }

        return response()->json(null, 204);
    }
}

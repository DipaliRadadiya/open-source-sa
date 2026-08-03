<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Application\RemoveCertificate;
use App\Actions\Server\Application\RequestCertificate;
use App\Actions\Server\Application\SetForceHttps;
use App\Actions\Server\Application\UploadCertificate;
use App\Enums\CertificateType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\StoreCertificateRequest;
use App\Http\Resources\CertificateResource;
use App\Models\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CertificateController extends Controller
{
    /**
     * `null` rather than a 404 when there is no certificate: "this site has no
     * certificate" is a normal state the screen has to render, not an error.
     */
    public function show(Application $application): JsonResponse
    {
        $certificate = $application->certificate;

        return response()->json([
            'certificate' => $certificate === null
                ? null
                : CertificateResource::make($certificate)->resolve(),
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
     * @throws ValidationException
     */
    public function forceHttps(Application $application, Request $request, SetForceHttps $action): JsonResponse
    {
        $validated = $request->validate(['force_https' => ['required', 'boolean']]);

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

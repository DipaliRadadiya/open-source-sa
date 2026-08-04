<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Services\Server\Doctor\Doctor;
use Illuminate\Http\JsonResponse;

/**
 * The self-check, for the admin screen.
 *
 * Read-only and safe to call at any time: every check is required to observe
 * the server without changing it.
 */
class DoctorController extends Controller
{
    public function show(Doctor $doctor): JsonResponse
    {
        return response()->json(['doctor' => $doctor->run()]);
    }
}

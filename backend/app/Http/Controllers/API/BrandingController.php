<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class BrandingController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'branding' => config('branding'),
        ]);
    }
}

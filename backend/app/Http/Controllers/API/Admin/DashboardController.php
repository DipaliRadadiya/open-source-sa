<?php

namespace App\Http\Controllers\API\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'dashboard' => [
                'users' => [
                    'total' => User::query()->count(),
                    'admin' => User::query()->where('role', UserRole::Admin)->count(),
                    'user' => User::query()->where('role', UserRole::User)->count(),
                ],
                'roles' => [
                    'total' => Role::query()->count(),
                ],
                'activity' => [
                    'today' => ActivityLog::query()->whereDate('created_at', Carbon::today())->count(),
                    'total' => ActivityLog::query()->count(),
                ],
            ],
        ]);
    }
}

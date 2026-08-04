<?php

namespace App\Http\Controllers\API\Admin;

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
                // People, not machine accounts — the counts here sit next to
                // the user list, and the two disagreeing reads as a bug.
                'users' => [
                    'total' => User::query()->where('is_system', false)->count(),
                    'admins' => User::query()->where('is_system', false)->where('is_admin', true)->count(),
                    'non_admins' => User::query()->where('is_system', false)->where('is_admin', false)->count(),
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

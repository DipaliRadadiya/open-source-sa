<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ActivityLogger
{
    /**
     * @param  array<string, mixed>  $properties
     * @param  User|null  $actor  Who performed the action. Defaults to the
     *                            currently authenticated user; must be
     *                            passed explicitly for actions that happen
     *                            before authentication is established
     *                            (register, login).
     */
    public function log(string $action, ?Model $subject = null, array $properties = [], ?User $actor = null): ActivityLog
    {
        $actor ??= Auth::user();

        return ActivityLog::create([
            'user_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'properties' => $properties,
        ]);
    }
}

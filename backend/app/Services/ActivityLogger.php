<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ActivityLogger
{
    /**
     * @param  string  $key  Dotted key `<type>.<verb>` (e.g.
     *                       `system_user.created`) — stored split into the
     *                       indexed `type` + `action` columns. The lang key
     *                       stays `activity.<type>.<verb>`.
     * @param  array<string, mixed>  $properties
     * @param  User|null  $actor  Who performed the action. Defaults to the
     *                            currently authenticated user; must be
     *                            passed explicitly for actions that happen
     *                            before authentication is established
     *                            (register, login).
     */
    public function log(string $key, ?Model $subject = null, array $properties = [], ?User $actor = null): ActivityLog
    {
        $actor ??= Auth::user();

        return ActivityLog::create([
            'user_id' => $actor?->getKey(),
            'type' => Str::before($key, '.'),
            'action' => Str::after($key, '.'),
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'properties' => $properties,
        ]);
    }
}

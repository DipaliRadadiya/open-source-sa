<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'username', 'system_user_id', 'command', 'expression', 'active'])]
class Cronjob extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function systemUser(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class);
    }
}

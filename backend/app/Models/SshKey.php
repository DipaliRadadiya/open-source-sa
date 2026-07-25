<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['system_user_id', 'name', 'public_key', 'fingerprint'])]
class SshKey extends Model
{
    public function systemUser(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class);
    }
}

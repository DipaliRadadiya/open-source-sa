<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

#[Fillable(['token_id', 'connected_by_user_id', 'connected_at', 'revoked_by_user_id', 'revoked_at'])]
class CentralConnection extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'connected_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * The connection currently in force, if any. There is at most one — the
     * controller refuses to connect while this returns a row.
     */
    public static function active(): ?self
    {
        return static::query()->whereNull('revoked_at')->latest('id')->first();
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by_user_id');
    }

    /**
     * The token this connection issued. Reads `last_used_at` from Sanctum's
     * own column, so "has Central actually called us?" needs no bookkeeping
     * of our own. Null once the token has been revoked.
     */
    public function token(): ?PersonalAccessToken
    {
        return $this->token_id === null
            ? null
            : PersonalAccessToken::find($this->token_id);
    }
}

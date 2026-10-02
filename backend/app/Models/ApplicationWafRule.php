<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['application_id', 'type', 'value'])]
class ApplicationWafRule extends Model
{
    /**
     * The shortest exception the firewall honours (bug #82). An exception
     * skips every check for any path containing it, so `a` or `/` switched
     * the firewall off for the whole site.
     */
    public const EXCEPTION_MIN_LENGTH = 4;

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}

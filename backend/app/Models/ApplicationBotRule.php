<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['application_id', 'type', 'value'])]
class ApplicationBotRule extends Model
{
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}

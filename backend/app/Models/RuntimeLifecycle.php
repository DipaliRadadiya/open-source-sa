<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RuntimeLifecycle extends Model
{
    protected $fillable = ['runtime', 'version', 'status', 'eol_date', 'lts_name'];

    protected function casts(): array
    {
        return ['eol_date' => 'date'];
    }
}

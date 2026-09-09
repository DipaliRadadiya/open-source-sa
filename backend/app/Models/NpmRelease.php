<?php

namespace App\Models;

use App\Services\Runtime\NpmCatalog;
use Illuminate\Database\Eloquent\Model;

/**
 * The newest npm release of one npm major, with the Node range it runs on.
 *
 * {@see NpmCatalog} owns reading and refreshing these.
 */
class NpmRelease extends Model
{
    protected $fillable = ['major', 'version', 'node_range'];
}

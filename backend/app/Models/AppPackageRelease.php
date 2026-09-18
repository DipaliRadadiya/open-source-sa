<?php

namespace App\Models;

use App\Services\Runtime\AppPackageCatalog;
use Illuminate\Database\Eloquent\Model;

/**
 * The release a one-click application's npm package currently resolves to,
 * and the Node range that release declares.
 *
 * {@see AppPackageCatalog} owns reading and refreshing these.
 */
class AppPackageRelease extends Model
{
    protected $primaryKey = 'package';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['package', 'version', 'node_range'];
}

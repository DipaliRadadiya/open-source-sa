<?php

namespace App\Contracts;

use App\Models\Application;

/**
 * The type-specific step Site Clone needs on top of its generic file+DB
 * copy: pointing the clone's own config at its new database and rewriting
 * whatever URLs got copied in, the same problem `StagingStrategy` solves for
 * Staging. Only site types where `needsDatabase()` is true and a recipe has
 * actually been built need one — everything else (static, blank PHP, node,
 * git with no database) clones fully generically with no strategy at all.
 */
interface CloneStrategy
{
    public function clone(Application $source, Application $clone): void;
}

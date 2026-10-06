<?php

namespace App\Services\Server\Applications\Cloning;

use App\Actions\Server\Database\CreateDatabase;
use App\Exceptions\Server\Application\CloneOperationException;
use App\Models\Application;
use App\Models\Database;
use App\Services\Server\Databases\DatabaseIdentifier;
use App\Services\Server\Databases\DatabaseManager;
use App\Services\Server\Databases\DatabasePassword;
use App\Services\Server\ServerOps;
use Illuminate\Support\Str;
use Throwable;

/**
 * A copy of one database for a clone: a new database and user of its own on
 * the same engine, holding the source's data, linked to the clone.
 *
 * Shared by the WordPress recipe and the git clone (CLN-01), which needed the
 * same three steps — and a clone that shares its source's database is not a
 * copy: every change made "on the copy" lands on the live site.
 */
class DatabaseCopy
{
    public function __construct(
        private DatabaseManager $databases,
        private DatabaseIdentifier $identifiers,
        private ServerOps $serverOps,
        private CreateDatabase $createDatabase,
    ) {}

    /**
     * The clone's database, its users loaded.
     */
    public function copy(Database $source, Application $sourceApplication, Application $clone): Database
    {
        $engine = $this->databases->engine($source->engine);
        $dumpPath = '/tmp/panel-clone-'.Str::uuid()->toString().'.sql';

        try {
            $engine->dump($source->name, $dumpPath);

            $copy = $this->create($sourceApplication, $clone, $source->engine);

            $engine->restore($copy->name, $dumpPath);
        } finally {
            // The dump holds the source's data; it does not outlive the copy,
            // whether or not the copy worked.
            $this->serverOps->run(['rm', '-f', $dumpPath], ['feature' => 'application', 'op' => 'clone_dump_cleanup', 'application' => $clone->id]);
        }

        return $copy->load('users');
    }

    private function create(Application $source, Application $clone, string $engine): Database
    {
        $name = $this->identifiers->generateAvailable($source->name, $engine, 'clone');

        try {
            return $this->createDatabase->execute([
                'name' => $name,
                'engine' => $engine,
                'application_id' => $clone->id,
                'create_user' => [
                    'username' => $name,
                    'password' => DatabasePassword::generate(),
                    'connection_preference' => 'localhost',
                ],
            ]);
        } catch (Throwable) {
            throw new CloneOperationException((string) Str::uuid());
        }
    }
}

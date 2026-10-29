<?php

/*
 * Writes what `config:cache` would, to the path in argv[1] instead of
 * bootstrap/cache/config.php. TestDatabaseGuardTest runs it in a child process
 * with a MySQL environment, then points a probe run at the file.
 */

use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

file_put_contents($argv[1], '<?php return '.var_export($app->make('config')->all(), true).';'.PHP_EOL);

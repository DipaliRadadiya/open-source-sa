<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Support\QueueDrainJob;

require __DIR__.'/../../../vendor/autoload.php';

$directory = $argv[1];
$mode = $argv[2];

// Private process group only: the portable test never addresses a real unit,
// daemon or privileged process. The parent verifies this identity before kill.
if (! posix_setpgid(0, 0)) {
    exit(2);
}

$app = require __DIR__.'/../../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
config([
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => $directory.'/queue.sqlite',
    'queue.default' => 'database',
    'cache.default' => 'array',
    'logging.default' => 'stderr',
]);
touch($directory.'/queue.sqlite');
Schema::create('jobs', function (Blueprint $table) {
    $table->id();
    $table->string('queue')->index();
    $table->longText('payload');
    $table->unsignedTinyInteger('attempts');
    $table->unsignedInteger('reserved_at')->nullable();
    $table->unsignedInteger('available_at');
    $table->unsignedInteger('created_at');
});
Schema::create('failed_jobs', function (Blueprint $table) {
    $table->id();
    $table->string('uuid')->unique();
    $table->text('connection');
    $table->text('queue');
    $table->longText('payload');
    $table->longText('exception');
    $table->timestamp('failed_at')->useCurrent();
});

$app['queue']->connection()->push(new QueueDrainJob($directory, 'first', $mode));
$app['queue']->connection()->push(new QueueDrainJob($directory, 'second', $mode));
file_put_contents($directory.'/worker.json', json_encode(['pid' => getmypid(), 'pgid' => posix_getpgrp()], JSON_THROW_ON_ERROR));

$status = $kernel->call('queue:work', [
    '--queue' => 'high,default', '--sleep' => 0, '--tries' => 1, '--timeout' => 20,
    '--max-jobs' => $mode === 'normal' ? 1 : 2, '--stop-when-empty' => true,
]);
file_put_contents($directory.'/worker-exited', (string) $status);
exit($status);

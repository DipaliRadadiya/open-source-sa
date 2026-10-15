<?php

use Symfony\Component\Process\Process;

require __DIR__.'/../../../vendor/autoload.php';

$directory = $argv[1];
$mode = $argv[2];
$grandchild = ($argv[3] ?? '') === 'grandchild';
$name = $grandchild ? 'grandchild' : 'child';
pcntl_async_signals(true);
pcntl_signal(SIGTERM, function () use ($directory, $mode, $name) {
    file_put_contents($directory.'/'.$name.'-interrupted', 'TERM');

    if ($mode !== 'deadline') {
        exit(143);
    }
});
file_put_contents($directory.'/'.$name.'.pid', (string) getmypid());

if ($grandchild) {
    while (! is_file($directory.'/release')) {
        usleep(10000);
    }
} else {
    (new Process([PHP_BINARY, __FILE__, $directory, $mode, 'grandchild']))->setTimeout(12)->mustRun();
}

file_put_contents($directory.'/'.$name.'-completed', '1');

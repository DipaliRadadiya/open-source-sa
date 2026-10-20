<?php

use App\Services\Server\HostCpus;

/*
 * OLD-2 (frontend QA): the core count was the number of times the word
 * "processor" appeared in /proc/cpuinfo, so a model name containing it
 * doubled the answer.
 */
beforeEach(function () {
    $this->procDir = sys_get_temp_dir().'/sv-oss-proc-'.bin2hex(random_bytes(4));
    mkdir($this->procDir);
    config(['server.proc_dir' => $this->procDir]);
});

afterEach(function () {
    @unlink($this->procDir.'/cpuinfo');
    @rmdir($this->procDir);
});

function cpuinfoWith(int $cores, string $model): void
{
    $blocks = [];
    for ($i = 0; $i < $cores; $i++) {
        $blocks[] = "processor\t: {$i}\nvendor_id\t: GenuineIntel\nmodel name\t: {$model}\ncpu cores\t: {$cores}\n";
    }
    file_put_contents(test()->procDir.'/cpuinfo', implode("\n", $blocks));
}

it('counts processor entries, not the word', function () {
    cpuinfoWith(4, 'Intel(R) Xeon(R) processor @ 2.20GHz');

    expect(app(HostCpus::class)->count())->toBe(4);
});

it('counts a plain cpuinfo', function () {
    cpuinfoWith(2, 'AMD EPYC 7B13');

    expect(app(HostCpus::class)->count())->toBe(2);
});

it('answers one when cpuinfo is missing', function () {
    expect(app(HostCpus::class)->count())->toBe(1);
});

<?php

use App\Enums\PanelUpdateStatus;
use App\Models\PanelUpdate;
use App\Services\Panel\PanelLayout;
use App\Services\Panel\PanelPhpBinary;
use App\Services\Panel\PanelReleases;
use App\Services\Panel\ReleaseUpdateScript;
use App\Services\Panel\UpdateScript;

/*
 * Both update flows name a frontend build the kernel killed for memory
 * (`frontend_build_out_of_memory`) instead of the generic `frontend_build`,
 * so the panel can say "out of memory, add swap" rather than "could not be
 * built". The build fragment is cut out of the rendered script and run under
 * bash with the npm line replaced, because what matters is how the shell
 * behaves: npm exits 1, not 137, when its child is killed, so the cgroup's
 * OOM count has to be what notices.
 */

/** The rendered script of each flow. */
function updateScripts(): array
{
    $layout = new PanelLayout('/var/www/panel/current/backend');
    $release = (new ReleaseUpdateScript($layout, new PanelReleases($layout), new PanelPhpBinary))
        ->render(new PanelUpdate(['id' => 1, 'from_commit' => str_repeat('a', 40)]), '1.0.2');

    $update = PanelUpdate::create(['status' => PanelUpdateStatus::Pending, 'to_version' => '99.0.0']);
    $legacy = app(UpdateScript::class)->render($update, '99.0.0');

    return ['release' => $release, 'in-place' => $legacy];
}

/**
 * The build fragment of $script, run with `npm run build` replaced by $build.
 * Returns the reason the rollback would record, or PASSED.
 */
function runBuildFragment(string $script, string $build): string
{
    $start = strpos($script, 'BUILD_CGROUP=');
    expect($start)->not->toBeFalse();
    $end = strpos($script, "\n}\n", $start);
    expect($end)->not->toBeFalse();
    $fragment = substr($script, $start, $end - $start + 3);

    $fragment = preg_replace('/^.*run build \|\| \{$/m', 'fake_build || {', $fragment, 1, $count);
    expect($count)->toBe(1);

    // This process's cgroup, under a fake /sys/fs/cgroup the test controls.
    $root = sys_get_temp_dir().'/update-oom-'.bin2hex(random_bytes(4));
    preg_match('/^0::(\S*)$/m', (string) file_get_contents('/proc/self/cgroup'), $m);
    $cgroup = $root.($m[1] ?? '');
    @mkdir($cgroup, 0777, true);
    file_put_contents($cgroup.'/memory.events', "low 0\nhigh 0\nmax 0\noom 0\noom_kill 4\n");
    $fragment = str_replace('/sys/fs/cgroup', $root, $fragment);

    $bash = 'set -uo pipefail
        STEP=frontend_build
        EVENTS='.escapeshellarg($cgroup.'/memory.events').'
        fake_build() { '.$build.'; }
        trap '.escapeshellarg('echo "REASON=$STEP"; exit 1').' ERR
        set -e
        '.$fragment.'
        echo PASSED';

    exec('bash -c '.escapeshellarg($bash).' 2>&1', $out, $code);
    exec('rm -rf '.escapeshellarg($root));

    preg_match('/REASON=(\S+)|PASSED/', implode("\n", $out), $r);

    return $r[1] ?? ($r[0] ?? implode("\n", $out));
}

it('names a build killed by SIGKILL as out of memory in the :dataset flow', function (string $flow) {
    expect(runBuildFragment(updateScripts()[$flow], 'sh -c "kill -9 \$\$"'))
        ->toBe('frontend_build_out_of_memory');
})->with(['release', 'in-place']);

it('names a build npm reported as a plain failure as out of memory when the cgroup counted an OOM kill, in the :dataset flow', function (string $flow) {
    expect(runBuildFragment(updateScripts()[$flow], 'sed -i "s/^oom_kill 4$/oom_kill 5/" "$EVENTS"; return 1'))
        ->toBe('frontend_build_out_of_memory');
})->with(['release', 'in-place']);

it('keeps the generic reason for a build that failed on its own in the :dataset flow', function (string $flow) {
    expect(runBuildFragment(updateScripts()[$flow], 'return 1'))->toBe('frontend_build');
})->with(['release', 'in-place']);

it('lets a build that succeeds carry on in the :dataset flow', function (string $flow) {
    expect(runBuildFragment(updateScripts()[$flow], 'return 0'))->toBe('PASSED');
})->with(['release', 'in-place']);

it('has a sentence for the out-of-memory reason in every language', function () {
    foreach (glob(lang_path('*'), GLOB_ONLYDIR) ?: [] as $dir) {
        $locale = basename($dir);
        expect(Lang::has('panel_update.reasons.frontend_build_out_of_memory', $locale, false))
            ->toBeTrue("lang/{$locale} has no reasons.frontend_build_out_of_memory");
    }
});

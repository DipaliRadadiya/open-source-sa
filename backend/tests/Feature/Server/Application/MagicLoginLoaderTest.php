<?php

use Illuminate\Support\Facades\Process;

/*
 * The WordPress side of Magic Login: resources/stubs/sv-magic-login.php, run
 * for real in a separate PHP process with stand-ins for the few WordPress
 * functions it calls. The option table is a JSON file, and delete_option()
 * answers true only when there was a row, as WordPress's does.
 *
 * Bug #97: the token was deleted before it was checked, so anyone posting
 * the field with any value cancelled the administrator's pending login.
 */

/**
 * Run one POST through the loader against the given stored option.
 *
 * @return array{output: string, option: mixed}
 */
function runMagicLoader(?array $stored, string $presented, bool $lostRace = false): array
{
    $dir = sys_get_temp_dir().'/sv-magic-'.uniqid();
    mkdir($dir);
    file_put_contents("{$dir}/options.json", json_encode($stored === null ? [] : ['sv_magic_login_token' => $stored]));

    $stub = resource_path('stubs/sv-magic-login.php');
    $harness = <<<PHP
        <?php
        define('ABSPATH', '/');
        \$GLOBALS['store'] = '{$dir}/options.json';
        function sv_opts() { return json_decode(file_get_contents(\$GLOBALS['store']), true); }
        function get_option(\$k) { return sv_opts()[\$k] ?? false; }
        function delete_option(\$k) { if (getenv('SV_LOST_RACE')) { return false; } \$o = sv_opts(); if (! array_key_exists(\$k, \$o)) { return false; } unset(\$o[\$k]); file_put_contents(\$GLOBALS['store'], json_encode(\$o)); return true; }
        function add_action(...\$a) {}
        function do_action(...\$a) {}
        function get_user_by(\$f, \$id) { return (object) ['ID' => \$id, 'user_login' => 'admin']; }
        function user_can(\$u, \$c) { return true; }
        function wp_set_current_user(\$id) {}
        function wp_set_auth_cookie(\$id, ...\$a) { echo "LOGGED_IN:{\$id}"; }
        function is_ssl() { return true; }
        function admin_url() { return '/wp-admin/'; }
        function wp_safe_redirect(\$u) {}
        \$_SERVER['REQUEST_METHOD'] = 'POST';
        \$_POST['sv_magic_login'] = \$argv[1];
        require '{$stub}';
        sv_magic_login_maybe_authenticate();
        PHP;

    file_put_contents("{$dir}/run.php", $harness);

    $result = Process::env($lostRace ? ['SV_LOST_RACE' => '1'] : [])->run([PHP_BINARY, "{$dir}/run.php", $presented]);
    $options = json_decode(file_get_contents("{$dir}/options.json"), true);

    array_map('unlink', glob("{$dir}/*"));
    rmdir($dir);

    expect($result->errorOutput())->toBe('');

    return ['output' => $result->output(), 'option' => $options['sv_magic_login_token'] ?? null];
}

function magicToken(string $token, int $expiresIn = 60): array
{
    return ['hash' => hash('sha256', $token), 'user_id' => 7, 'expires_at' => time() + $expiresIn];
}

it('signs in with the right token, once', function () {
    $run = runMagicLoader(magicToken('right'), 'right');

    expect($run['output'])->toBe('LOGGED_IN:7')
        ->and($run['option'])->toBeNull();

    // Single use: the same value again finds nothing.
    expect(runMagicLoader(null, 'right')['output'])->toBe('');
});

it('lets a wrong value cancel nothing (bug #97)', function () {
    $stored = magicToken('right');

    $wrong = runMagicLoader($stored, 'guess');

    expect($wrong['output'])->toBe('')
        ->and($wrong['option'])->toBe($stored);

    // The administrator's real link still works afterwards.
    expect(runMagicLoader($wrong['option'], 'right')['output'])->toBe('LOGGED_IN:7');
});

it('signs in only the request that consumed the token', function () {
    // Two requests with the right token at once both read it; the second's
    // DELETE removes nothing, which is how it knows it lost.
    expect(runMagicLoader(magicToken('right'), 'right', lostRace: true)['output'])->toBe('');
});

it('refuses and clears an expired token', function () {
    $run = runMagicLoader(magicToken('right', expiresIn: -5), 'right');

    expect($run['output'])->toBe('')
        ->and($run['option'])->toBeNull();
});

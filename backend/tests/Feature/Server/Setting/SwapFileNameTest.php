<?php

/**
 * 🔴 The installer and the panel have to mean the same file.
 *
 * install.sh creates `/swapfile-panel`; the panel managed `/swapfile`. Both
 * were internally consistent and they never met, so on every fresh install the
 * installer's gigabyte was live and the Memory screen reported swap **off, 0
 * bytes** — because `SwapSettings::read()` answers `enabled` by looking for
 * *its* configured path in the kernel's swap list, and that path was never
 * there. Reported from a real box as "1 GB of swap during panel installation,
 * off afterwards".
 *
 * Nothing connected the two names, which is why they could drift. This is that
 * connection: rename either side alone and the suite fails instead of the
 * server.
 */
it('manages the swap file the installer actually creates', function () {
    $installer = (string) file_get_contents(base_path('../install.sh'));

    expect(preg_match('/^\s*local swapfile=(\S+)\s*$/m', $installer, $matches))->toBe(1);

    // The shipped *default*, read as source rather than through `config()`.
    //
    // Two reasons, both of which would make this guard lie. Sibling swap tests
    // override `server.swap_file` to a temp directory, and a guard another
    // test can silently satisfy is not a guard. And an operator who sets
    // `SERVER_SWAP_FILE` deliberately — the documented escape hatch for a
    // server whose swap predates this change — would otherwise fail a suite
    // about something they configured on purpose.
    $config = (string) file_get_contents(base_path('config/server.php'));

    expect(preg_match("/'swap_file' => env\('SERVER_SWAP_FILE', '([^']+)'\)/", $config, $default))->toBe(1);

    expect($default[1])->toBe($matches[1])
        ->and($default[1])->toBe('/swapfile-panel');
});

<?php

/*
 * A restore writes into the site's own directory, and the code doing it runs
 * as the panel's unprivileged queue worker — which has no access there. PHP's
 * native filesystem functions therefore cannot be used on those paths: they
 * either fail with a bare "mkdir(): Permission denied" that names neither the
 * path nor the reason (which is how this was found, mid-restore, in
 * production) or, worse, return false silently and leave a half-unpacked copy
 * of a site sitting next to the live one.
 *
 * Everything touching a site path has to go through ServerOps, which elevates.
 *
 * Asserted statically rather than by running a restore: the behaviour only
 * diverges when the process genuinely lacks permission, so a test suite
 * running as a user who happens to have it would pass either way and prove
 * nothing.
 */

$siteWritingSteps = [
    'ExtractArchive.php',
    'SwapFiles.php',
];

it('never touches a site directory with PHP\'s own filesystem functions', function (string $file) {
    // Not app_path(): only Feature tests boot the framework, and this needs
    // nothing from it beyond the file on disk.
    $path = __DIR__.'/../../app/Services/Server/Restores/Steps/'.$file;

    expect($path)->toBeReadableFile();

    // Comments stripped first: these files explain *why* File:: is banned, and
    // matching the prose that documents the rule would make the rule
    // unstatable.
    $source = stripPhpComments((string) file_get_contents($path));

    // `File::` is the Illuminate facade, which is mkdir()/rmdir()/unlink()
    // under the panel's own user; the bare functions are the same thing
    // spelled differently.
    expect($source)
        ->not->toContain('File::')
        ->not->toMatch('/(?<![\w:>$])(mkdir|rmdir|unlink|rename|copy)\s*\(/');
})->with($siteWritingSteps);

function stripPhpComments(string $source): string
{
    $out = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $out .= is_array($token) ? $token[1] : $token;
    }

    return $out;
}

it('cleans the staging directory through ServerOps, not File::', function () {
    // The staging directory lives beside the live site, so the runner's own
    // cleanup is subject to the same rule as the steps. File::deleteDirectory()
    // there returns false rather than throwing, so getting this wrong is
    // silent — it leaves behind exactly the partial copy it exists to remove.
    $source = stripPhpComments(
        (string) file_get_contents(__DIR__.'/../../app/Services/Server/Restores/RestoreRunner.php')
    );

    $cleanup = substr($source, (int) strpos($source, 'stagingDirectory !== null'), 400);

    expect($cleanup)
        ->toContain('serverOps')
        ->not->toContain('File::deleteDirectory($context->stagingDirectory)');
});

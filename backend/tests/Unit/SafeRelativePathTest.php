<?php

use App\Rules\SafeRelativePath;

/*
 * The rule guarding every client-supplied path in the file manager.
 *
 * It used to allow only [A-Za-z0-9._- ], which refused most real filenames:
 * uploading `logo@2x.png` was answered with "That path is not allowed."
 *
 * The allowlist was there to stop shell injection, and shell injection is not
 * reachable — ServerOps runs array arguments through Process with no shell, so
 * `;` and `$(…)` are ordinary characters nothing parses. What is dangerous is
 * a short, enumerable list, and each item below is one of them.
 */

it('accepts the filenames people actually have', function (string $path) {
    expect(SafeRelativePath::isSafe($path))->toBeTrue();
})->with([
    'retina asset' => 'logo@2x.png',
    'parenthesised copy' => 'report (final).pdf',
    'accents' => 'résumé.pdf',
    'han characters' => '发票.pdf',
    'emoji' => 'holiday 🏖.jpg',
    'punctuation' => "a+b&c#d'e!.txt",
    'percent' => '100%-done.txt',
    'nested' => 'wp-content/uploads/2026/photo.jpeg',
    'dotfile' => '.htaccess',
    'the root itself' => '',
]);

it('refuses what can actually do harm', function (string $path) {
    expect(SafeRelativePath::isSafe($path))->toBeFalse();
})->with([
    // Traversal, in each of the shapes it arrives in.
    'parent segment' => '../etc/passwd',
    'parent in the middle' => 'a/../../etc/passwd',
    'absolute' => '/etc/passwd',
    'bare dot' => '.',
    'bare parent' => '..',
    'empty segment' => 'a//b',

    // `find <path>` and `rm <path>` read these as options, and passing
    // arguments as an array does not help — only the absence of the name does.
    'leading dash' => '-rf',
    'leading dash deeper' => 'uploads/-rf',

    // The listing is parsed from tab-separated, newline-delimited find output;
    // either character in a name shifts every field after it.
    'tab' => "two\tcolumns.txt",
    'newline' => "two\nlines.txt",
    'null byte' => "sneaky\0.txt",

    // A zip written on Windows uses this as a separator and nothing here can
    // tell that apart from a Linux filename that merely contains one.
    'backslash' => 'windows\\path.txt',
]);

it('refuses a name longer than the filesystem allows', function () {
    // NAME_MAX is 255 *bytes*. A clear refusal beats ENAMETOOLONG surfacing
    // from whichever command happened to touch it first.
    expect(SafeRelativePath::isSafe(str_repeat('a', 255)))->toBeTrue()
        ->and(SafeRelativePath::isSafe(str_repeat('a', 256)))->toBeFalse()
        // Bytes, not characters: each of these is three bytes.
        ->and(SafeRelativePath::isSafe(str_repeat('あ', 86)))->toBeFalse();
});

it('refuses bytes that are not valid UTF-8', function () {
    // They cannot survive the JSON response, and a `/u` pattern would fail
    // open on them rather than report a mismatch.
    expect(SafeRelativePath::isSafe("bad\xC3\x28name.txt"))->toBeFalse();
});

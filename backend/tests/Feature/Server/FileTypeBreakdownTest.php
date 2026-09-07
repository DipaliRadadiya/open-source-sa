<?php

use App\Services\Server\Applications\FileTypeBreakdown;

/*
 * Categorising by extension, and the arithmetic on top of it.
 *
 * Pure: no server, no files. The walk that feeds this is tested through the
 * endpoint; this is the part that decides what a name means, which is where
 * the judgement calls are.
 */

beforeEach(function () {
    $this->types = new FileTypeBreakdown;
});

it('reads the category from the extension', function () {
    expect($this->types->categorise('hero.jpg'))->toBe('images')
        ->and($this->types->categorise('promo.MP4'))->toBe('video')
        ->and($this->types->categorise('app.js'))->toBe('code')
        ->and($this->types->categorise('backup.sql'))->toBe('database')
        ->and($this->types->categorise('laravel.log'))->toBe('logs')
        ->and($this->types->categorise('site.tar.gz'))->toBe('archives');
});

it('does not invent a type from a dotfile', function () {
    // `.gitignore` has no extension in the sense that matters: the leading dot
    // is not a separator. Splitting on it would file this under "gitignore".
    expect($this->types->categorise('.gitignore'))->toBe('other')
        ->and($this->types->categorise('.env'))->toBe('other');
});

it('still reads a dotfile that genuinely has one', function () {
    expect($this->types->categorise('.eslintrc.json'))->toBe('code');
});

it('says other rather than guessing', function () {
    expect($this->types->categorise('README'))->toBe('other')
        ->and($this->types->categorise('data.qqq'))->toBe('other');
});

it('totals bytes and files per category, largest first', function () {
    $summary = $this->types->summarise([
        ['name' => 'a.jpg', 'size' => 100],
        ['name' => 'b.png', 'size' => 300],
        ['name' => 'c.js', 'size' => 50],
    ]);

    expect($summary[0])->toMatchArray(['key' => 'images', 'bytes' => 400, 'count' => 2])
        ->and($summary[1])->toMatchArray(['key' => 'code', 'bytes' => 50, 'count' => 1]);
});

it('lets unrecognised files rank on their size like anything else', function () {
    // If the thing filling the disk is unrecognised, that IS the answer.
    // Pinning "other" last as a convention would bury it.
    $summary = $this->types->summarise([
        ['name' => 'huge.bin', 'size' => 9000],
        ['name' => 'small.jpg', 'size' => 10],
    ]);

    expect($summary[0]['key'])->toBe('other');
});

it('summarises nothing as nothing', function () {
    expect($this->types->summarise([]))->toBe([]);
});

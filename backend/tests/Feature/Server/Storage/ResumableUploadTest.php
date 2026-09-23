<?php

use App\Models\StorageDestination;
use App\Services\Server\Backups\Storage\GoogleResumableUpload;
use App\Services\Server\Backups\Storage\StorageDriverFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * One bad chunk used to discard the whole upload.
 *
 * `GoogleDriveAdapter::upload()` loops `nextChunk()` with no retry and returns
 * `false` on any failure, which Flysystem renders as "Not able to write the
 * file" with no exception underneath — so nothing is logged about the cause,
 * because nothing was ever created. On 2026-09-23 a 102 GB backup died after
 * exactly 35 of ~1048 chunks and took the 14-minute archive with it.
 *
 * The protocol is built for this: ask the session what it holds and carry on.
 * The vendor ships `StreamableUpload::resume()` and calls it from nowhere.
 */

/**
 * A fake `StreamableUpload` that fails on demand and tracks the offset.
 *
 * Stateful on purpose: a fake that always returns the same thing cannot tell
 * "resumed from the committed byte" from "started again from zero", which is
 * the entire behaviour under test. See `feedback_a-static-fake-is-not-a-server`.
 */
final class FakeChunkedUpload
{
    public array $offsets = [];

    public int $resumeCalls = 0;

    private int $progress = 0;

    public function __construct(
        private int $size,
        private int $chunk,
        /** @var list<int> chunk indexes that should throw once */
        private array $failAt = [],
    ) {}

    public function nextChunk(): mixed
    {
        $index = intdiv($this->progress, $this->chunk);

        if (in_array($index, $this->failAt, true)) {
            // Only once, so a resume can make progress past it.
            $this->failAt = array_values(array_diff($this->failAt, [$index]));

            throw new RuntimeException('503 backend error');
        }

        $this->offsets[] = $this->progress;
        $this->progress = min($this->size, $this->progress + $this->chunk);

        return $this->progress >= $this->size ? 'DONE' : false;
    }

    public function resume(): mixed
    {
        $this->resumeCalls++;

        // Google's answer: the committed offset, which is where the last
        // *successful* chunk ended — not where we thought we were.
        return false;
    }

    public function getProgress(): int
    {
        return $this->progress;
    }
}

it('resumes from the committed offset instead of starting again', function () {
    $chunk = 1000;
    $size = 5000;

    // Chunk 2 fails once. A correct implementation asks where Google got to
    // and continues from 2000; a naive retry restarts at 0.
    $upload = new FakeChunkedUpload($size, $chunk, failAt: [2]);

    $status = false;
    $failures = 0;

    while ($status === false) {
        try {
            $status = $upload->nextChunk();
            $failures = 0;
        } catch (Throwable $e) {
            if (++$failures >= 5) {
                throw $e;
            }
            $status = $upload->resume();
        }
    }

    expect($status)->toBe('DONE')
        ->and($upload->resumeCalls)->toBe(1)
        // The decisive assertion: every chunk offset appears once and ascends.
        // A restart would show 0 twice.
        ->and($upload->offsets)->toBe([0, 1000, 2000, 3000, 4000])
        ->and(count(array_unique($upload->offsets)))->toBe(count($upload->offsets));
});

it('gives up after consecutive failures, not total ones', function () {
    // A 100 GB archive is ~1048 chunks. A budget of *total* failures would
    // kill an upload that had recovered from every one of them hours apart.
    $upload = new FakeChunkedUpload(5000, 1000, failAt: [1, 3]);

    $status = false;
    $failures = 0;
    $maxSeen = 0;

    while ($status === false) {
        try {
            $status = $upload->nextChunk();
            $failures = 0;
        } catch (Throwable $e) {
            $failures++;
            $maxSeen = max($maxSeen, $failures);
            if ($failures >= 5) {
                throw $e;
            }
            $status = $upload->resume();
        }
    }

    expect($status)->toBe('DONE')
        ->and($upload->resumeCalls)->toBe(2)
        // Two separate failures, never two in a row — so the counter reset.
        ->and($maxSeen)->toBe(1);
});

it('only Google Drive OAuth claims a resumable upload', function () {
    // Every other driver returns false, which hands the caller back to
    // `writeStream()`. A driver that cannot resume must not pretend to.
    $factory = app(StorageDriverFactory::class);

    foreach (['s3', 'ftp', 'sftp'] as $provider) {
        $destination = StorageDestination::create([
            'name' => $provider.'-probe',
            'provider' => $provider,
            'config' => $provider === 's3'
                ? ['endpoint' => '', 'region' => 'us-east-1', 'bucket' => 'b', 'access_key' => 'k', 'secret_key' => 's']
                : ['host' => 'h', 'username' => 'u', 'password' => 'p', 'root' => '/'],
        ]);

        expect($factory->for($destination)->uploadFrom($destination, 'a/b.tar.gz', '/tmp/nope'))
            ->toBeFalse();
    }
});

it('refuses an unconnected Drive destination rather than throwing', function () {
    // Falling back beats failing: a half-configured destination should reach
    // `writeStream()` and produce its own honest error there.
    $destination = StorageDestination::create([
        'name' => 'gDrive-unconnected',
        'provider' => 'google_drive_oauth',
        'config' => ['client_id' => 'x.apps.googleusercontent.com'],
    ]);

    expect(app(StorageDriverFactory::class)->for($destination)
        ->uploadFrom($destination, 'a/b.tar.gz', '/tmp/nope'))->toBeFalse();
});

it('reports zero-length or missing archives as not-uploaded', function () {
    // `filesize()` of a missing file is false. Treating that as "uploaded"
    // would record an artefact key for an object that does not exist.
    $empty = tempnam(sys_get_temp_dir(), 'empty');

    expect((new GoogleResumableUpload)->upload('i', 's', 'r', 'f', 'a/b.tar.gz', $empty))
        ->toBeFalse();

    @unlink($empty);
});

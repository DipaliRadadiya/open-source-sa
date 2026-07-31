<?php

use App\Models\ServerCapability;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    // Detection shells out; nothing here is testing detection itself.
    Process::fake();
});

it('records the stack the installer built', function () {
    $this->artisan('server:record-stack', ['stack' => 'lemp'])->assertSuccessful();

    $record = ServerCapability::query()->firstOrFail();

    expect($record->stack)->toBe('lemp');
    expect($record->web_server)->toBe('nginx');
    // `installer` rather than `detected`: this server's history is known, which
    // is the whole point of the command.
    expect($record->source)->toBe('installer');
});

it('maps mern to nginx, because mern is not a web server', function () {
    $this->artisan('server:record-stack', ['stack' => 'mern'])->assertSuccessful();

    expect(ServerCapability::query()->value('web_server'))->toBe('nginx');
});

it('refuses an unknown stack without touching the record', function () {
    $this->artisan('server:record-stack', ['stack' => 'lemp'])->assertSuccessful();

    $this->artisan('server:record-stack', ['stack' => 'kubernetes'])->assertFailed();

    // The earlier value survives — a typo in the installer must not blank out
    // what the panel already knew.
    expect(ServerCapability::query()->value('stack'))->toBe('lemp');
});

it('is safe to run twice', function () {
    // The installer is re-runnable, so everything it calls has to be.
    $this->artisan('server:record-stack', ['stack' => 'lemp'])->assertSuccessful();
    $this->artisan('server:record-stack', ['stack' => 'lamp'])->assertSuccessful();

    expect(ServerCapability::query()->count())->toBe(1);
    expect(ServerCapability::query()->value('web_server'))->toBe('apache');
});

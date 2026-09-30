<?php

use App\Services\Server\DiskCleaner\Targets\TmpTarget;
use App\Support\Scheduling\BootScopedEventMutex;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Process;

/*
 * A scheduled command's "not twice at once" lock outlived the server going
 * down mid-run: Redis kept it across the reboot and it blocked that command
 * for 24 hours. Seen on all three test servers on 2026-09-30 — scheduled
 * backups had stopped on one of them.
 */

beforeEach(function () {
    $this->bootFile = tempnam(sys_get_temp_dir(), 'boot');
    config(['server.boot_id_path' => $this->bootFile]);
    file_put_contents($this->bootFile, "boot-a\n");
});

afterEach(function () {
    @unlink($this->bootFile);
});

function scheduledEvent(string $command): Event
{
    return collect(app(Schedule::class)->events())
        ->first(fn (Event $e) => str_contains((string) $e->command, $command));
}

it('uses the boot-scoped lock for every scheduled command', function () {
    expect(app(EventMutex::class))->toBeInstanceOf(BootScopedEventMutex::class)
        ->and(scheduledEvent('backups:run-due')->mutex)->toBeInstanceOf(BootScopedEventMutex::class);
});

it('does not let a lock from before a reboot block the command', function () {
    $event = scheduledEvent('backups:run-due');
    $mutex = app(EventMutex::class);

    // A run that the server went down in the middle of: never forgotten.
    expect($mutex->create($event))->toBeTrue()
        ->and($mutex->exists($event))->toBeTrue();

    file_put_contents($this->bootFile, "boot-b\n");

    expect($mutex->exists($event))->toBeFalse()
        ->and($mutex->create($event))->toBeTrue();
});

it('still keeps two copies apart within one boot', function () {
    $event = scheduledEvent('backups:run-due');
    $mutex = app(EventMutex::class);

    expect($mutex->create($event))->toBeTrue()
        ->and($mutex->create($event))->toBeFalse();

    $mutex->forget($event);

    expect($mutex->exists($event))->toBeFalse();
});

it('leaves the event\'s own lock name as it found it', function () {
    $event = scheduledEvent('backups:run-due');
    $before = $event->mutexName();

    app(EventMutex::class)->create($event);

    expect($event->mutexName())->toBe($before);
});

it('is still cleared by schedule:clear-cache', function () {
    $event = scheduledEvent('backups:run-due');
    app(EventMutex::class)->create($event);

    $this->artisan('schedule:clear-cache')->assertSuccessful();

    expect(app(EventMutex::class)->exists($event))->toBeFalse();
});

it('behaves as before where the kernel publishes no boot ID', function () {
    config(['server.boot_id_path' => '/nonexistent/boot_id']);
    $event = scheduledEvent('backups:run-due');

    expect(BootScopedEventMutex::bootId())->toBe('')
        ->and(app(EventMutex::class)->create($event))->toBeTrue()
        ->and(app(EventMutex::class)->exists($event))->toBeTrue();
});

it('gives every non-overlapping command an expiry well under Laravel\'s 24 hours', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn (Event $e) => $e->withoutOverlapping);

    expect($events)->not->toBeEmpty();

    foreach ($events as $event) {
        expect($event->expiresAt)->toBeLessThanOrEqual(120, (string) $event->command);
    }

    // Backups only queue the work; a crashed tick must not cost more than a
    // few minutes of schedule.
    expect(scheduledEvent('backups:run-due')->expiresAt)->toBeLessThanOrEqual(10);
});

it('never cleans OpenLiteSpeed\'s runtime directory out of /tmp', function () {
    $ran = new ArrayObject;
    Process::fake(function ($process) use ($ran) {
        $ran->append($process->command);

        return Process::result(output: '');
    });

    app(TmpTarget::class)->clean();

    $find = collect($ran)->first(fn (array $c) => in_array('find', $c, true) && in_array('-delete', $c, true));

    expect($find)->not->toBeNull()
        ->and(implode(' ', $find))->toContain('! -path /tmp/lshttpd/*');
});

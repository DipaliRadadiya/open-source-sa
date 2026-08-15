<?php

namespace Tests\Feature\Server;

use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    // Feature access is role-based with no admin bypass, and the Administrator
    // role can only hold permissions that exist — so without the seeder the
    // admin has none of them and every route here answers 403.
    $this->seed(PermissionSeeder::class);

    Sanctum::actingAs(User::factory()->admin()->create());

    $this->systemUser = SystemUser::factory()->create();
    $this->application = Application::factory()->create([
        'system_user_id' => $this->systemUser->id,
    ]);
});

it('exposes directory_size_bytes in application resource when set', function () {
    $this->application->update(['directory_size_bytes' => 1024 * 1024 * 50]);

    $response = $this->getJson("/api/applications/{$this->application->id}");

    $response->assertOk()
        ->assertJsonPath('application.directory_size_bytes', 1024 * 1024 * 50);
});

it('returns a null size rather than dropping the field when never measured', function () {
    $this->application->update(['directory_size_bytes' => null]);

    // Present-and-null, not absent: the frontend has to be able to tell "not
    // measured yet" from "this endpoint does not report size", and a column
    // that disappears from some rows can do neither.
    $this->getJson("/api/applications/{$this->application->id}")
        ->assertOk()
        ->assertJsonPath('application.directory_size_bytes', null)
        ->assertJsonPath('application.directory_size_measured_at', null);
});

it('reports when the size was measured', function () {
    $this->application->update([
        'directory_size_bytes' => 2048,
        'directory_size_updated_at' => now()->subHours(3),
    ]);

    $response = $this->getJson("/api/applications/{$this->application->id}")->assertOk();

    expect($response->json('application.directory_size_bytes'))->toBe(2048)
        ->and($response->json('application.directory_size_measured_at'))->not->toBeNull()
        ->and($response->json('application.directory_size_measured_at_human'))->toContain('hours');
});

/**
 * `du` returns the size asked for; the directory probes that run first answer
 * "yes, a directory". Without those the browser 404s before it ever measures.
 */
function fakeDu(int $bytes): void
{
    Process::fake(function ($process) use ($bytes) {
        $command = $process->command;

        if (in_array('du', $command, true)) {
            return Process::result(output: $bytes."\t/home/x\n");
        }

        // `stat()` asks find for a type and a size.
        if (in_array('-printf', $command, true)) {
            return Process::result(output: "d\t4096");
        }

        return Process::result(exitCode: 0);
    });
}

describe('measuring on demand', function () {
    it('measures the site root and remembers when', function () {
        fakeDu(5242880);

        $response = $this->postJson("/api/applications/{$this->application->id}/directory-size")
            ->assertOk();

        expect($response->json('directory_size.size'))->toBe(5242880)
            ->and($response->json('directory_size.measured_at'))->not->toBeNull();

        expect($this->application->fresh()->directory_size_bytes)->toBe(5242880)
            ->and($this->application->fresh()->directory_size_updated_at)->not->toBeNull();
    });

    it('re-measures rather than handing back the stored number', function () {
        $this->application->update([
            'directory_size_bytes' => 111,
            'directory_size_updated_at' => now()->subDay(),
        ]);

        fakeDu(999);

        // The whole point of the button: a cached value is what it is replacing.
        $this->postJson("/api/applications/{$this->application->id}/directory-size")
            ->assertOk()
            ->assertJsonPath('directory_size.size', 999);
    });

    it('does not let a subfolder redefine how big the site is', function () {
        $this->application->update(['directory_size_bytes' => 5000000]);

        fakeDu(42);

        // Asking for one folder used to write its size into the application's,
        // so a glance at uploads permanently shrank the site.
        $this->getJson("/api/applications/{$this->application->id}/files/size?path=wp-content/uploads");

        expect($this->application->fresh()->directory_size_bytes)->toBe(5000000);
    });
});

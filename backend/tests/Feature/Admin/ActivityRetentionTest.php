<?php

use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * 2026-10-29 (Dipali): the activity log grew forever. An admin chooses how
 * long it is kept — 3, 6, 12 or 24 months, or everything (the default) — and
 * a daily clean-up deletes what is older.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
});

function activityAged(int $days, string $action = 'updated'): ActivityLog
{
    $row = ActivityLog::create(['type' => 'setting', 'action' => $action, 'properties' => ['group' => 'general']]);
    DB::table('activity_logs')->where('id', $row->id)->update(['created_at' => now()->subDays($days)]);

    return $row;
}

function diskRunAged(int $days): int
{
    return DB::table('disk_cleaner_runs')->insertGetId([
        'trigger' => 'manual', 'categories' => '[]', 'status' => 'completed',
        'created_at' => now()->subDays($days), 'updated_at' => now()->subDays($days),
    ]);
}

function addonRunAged(int $days, string $status): string
{
    $id = (string) Str::ulid();
    DB::table('addon_runs')->insert([
        'id' => $id, 'addon' => 'wp', 'command' => 'x', 'status' => $status, 'arguments' => '[]',
        'created_at' => now()->subDays($days), 'updated_at' => now()->subDays($days),
    ]);

    return $id;
}

describe('the setting', function () {
    it('keeps everything by default, and says how much there is', function () {
        activityAged(400);

        $this->actingAs($this->admin)->getJson('/api/admin/activity-log/retention')
            ->assertOk()
            ->assertJsonPath('retention.months', null)
            ->assertJsonPath('retention.choices', [3, 6, 12, 24])
            ->assertJsonPath('retention.entries', 1);
    });

    it('saves a choice, and logs it', function () {
        $this->actingAs($this->admin)->putJson('/api/admin/activity-log/retention', ['months' => 12])
            ->assertOk()
            ->assertJsonPath('retention.months', 12);

        $row = ActivityLog::where('action', 'activity_retention_updated')->latest('id')->first();
        expect(ActivityLogResource::make($row)->resolve()['description'])->toContain('12 months');
    });

    it('goes back to keeping everything', function () {
        $this->actingAs($this->admin)->putJson('/api/admin/activity-log/retention', ['months' => 12]);
        $this->actingAs($this->admin)->putJson('/api/admin/activity-log/retention', ['months' => null])
            ->assertOk()
            ->assertJsonPath('retention.months', null);

        expect(ActivityLog::where('action', 'activity_retention_cleared')->exists())->toBeTrue();
    });

    it('refuses anything but the fixed choices', function (mixed $months) {
        $this->actingAs($this->admin)->putJson('/api/admin/activity-log/retention', ['months' => $months])
            ->assertJsonValidationErrors('months');
    })->with([5, 0, 1, 36, 'twelve']);

    it('refuses a request that does not say', function () {
        $this->actingAs($this->admin)->putJson('/api/admin/activity-log/retention', [])
            ->assertJsonValidationErrors('months');
    });

    it('is for admins only', function () {
        $user = User::factory()->create();
        grantPermission($user, 'activity_log', view: true, manage: true);

        $this->actingAs($user)->getJson('/api/admin/activity-log/retention')->assertForbidden();
        $this->actingAs($user)->putJson('/api/admin/activity-log/retention', ['months' => 3])->assertForbidden();
    });
});

describe('the daily clean-up', function () {
    it('deletes nothing while everything is kept', function () {
        $old = activityAged(2000);

        Artisan::call('activity:prune');

        expect(ActivityLog::find($old->id))->not->toBeNull();
    });

    it('deletes entries older than the choice, and keeps newer ones', function () {
        $this->actingAs($this->admin)->putJson('/api/admin/activity-log/retention', ['months' => 3]);
        $old = activityAged(120);
        $recent = activityAged(60);
        $today = activityAged(0);

        Artisan::call('activity:prune');

        expect(ActivityLog::find($old->id))->toBeNull()
            ->and(ActivityLog::find($recent->id))->not->toBeNull()
            ->and(ActivityLog::find($today->id))->not->toBeNull();
    });

    it('takes old finished task runs with it, never one still running', function () {
        $this->actingAs($this->admin)->putJson('/api/admin/activity-log/retention', ['months' => 3]);
        $oldRun = diskRunAged(120);
        $newRun = diskRunAged(10);
        $finished = addonRunAged(120, 'succeeded');
        $running = addonRunAged(120, 'running');

        Artisan::call('activity:prune');

        expect(DB::table('disk_cleaner_runs')->where('id', $oldRun)->exists())->toBeFalse()
            ->and(DB::table('disk_cleaner_runs')->where('id', $newRun)->exists())->toBeTrue()
            ->and(DB::table('addon_runs')->where('id', $finished)->exists())->toBeFalse()
            ->and(DB::table('addon_runs')->where('id', $running)->exists())->toBeTrue();
    });

    it('records what it deleted, in a row it does not delete', function () {
        $this->actingAs($this->admin)->putJson('/api/admin/activity-log/retention', ['months' => 3]);
        activityAged(120);
        activityAged(130);

        Artisan::call('activity:prune');

        $row = ActivityLog::where('action', 'activity_entries_deleted')->latest('id')->first();
        expect($row)->not->toBeNull()
            ->and(ActivityLogResource::make($row)->resolve()['description'])->toContain('2 activity log entries');
    });

    it('is scheduled daily', function () {
        Artisan::call('schedule:list');

        expect(Artisan::output())->toContain('activity:prune');
    });
});

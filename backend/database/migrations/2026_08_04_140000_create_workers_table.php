<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A long-running process belonging to one application — a queue
        // worker, Horizon, or any command the site needs kept alive.
        //
        // Note what is NOT here: any column describing whether it is running.
        // systemd already answers that, and a stored copy is free to drift the
        // moment anything crashes or is touched from a shell. Same rule the
        // application's own process already follows.
        Schema::create('workers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('command');

            // Which preset produced it, or `custom`. Kept because the restart
            // strategy differs per kind: a Laravel queue worker is restarted
            // gracefully with `queue:restart`, Horizon with `horizon:terminate`,
            // and anything else by restarting the unit.
            $table->string('kind')->default('custom');

            // Working directory. Null means the application's document root,
            // which is what almost everyone wants and nobody should have to type.
            $table->string('directory')->nullable();

            // How many copies to run. systemd owns the instances (templated
            // units); this is only the number we ask for.
            $table->unsignedSmallInteger('processes')->default(1);

            // Seconds to let a process finish its current job after SIGTERM.
            // A queue worker killed mid-job leaves that job half-done, which
            // for anything touching money is the failure that matters.
            $table->unsignedSmallInteger('stop_wait_seconds')->default(30);

            $table->boolean('auto_restart')->default(true);

            // Whether a deploy restarts it. Default on: a queue worker holds
            // the old code in memory forever otherwise, with no error anywhere.
            $table->boolean('restart_on_deploy')->default(true);

            $table->boolean('enabled')->default(true);

            $table->timestamps();

            $table->unique(['application_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workers');
    }
};

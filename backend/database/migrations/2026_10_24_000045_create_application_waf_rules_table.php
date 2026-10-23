<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two kinds of one-line rule the site owner can add on top of the
     * built-in 8G categories, sharing one table since they're the same
     * shape and only differ in which direction they act:
     *
     * - `exception`: never block a request matching this string, even if a
     *   category otherwise would (the documented real-world fixes — a forum
     *   plugin's own path, `phpinfo()` — are exactly this).
     * - `block`: always block a request matching this string, even if none
     *   of the six categories would have caught it.
     */
    public function up(): void
    {
        Schema::create('application_waf_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // exception | block
            $table->string('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_waf_rules');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firewall_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('port_from');
            // Set for a port range (port_from..port_to); null for a single port.
            $table->unsignedInteger('port_to')->nullable();
            $table->string('protocol')->default('tcp'); // all | tcp | udp
            $table->string('action')->default('allow'); // allow | deny
            // Source IPv4/IPv6 address or CIDR; null = anywhere.
            $table->string('source_ip')->nullable();
            $table->string('description')->nullable();
            // user (hand-made) | default (seeded on enable) | db_user (from a
            // remote DB user) — drives UI badges + delete protection.
            $table->string('origin')->default('user');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firewall_rules');
    }
};

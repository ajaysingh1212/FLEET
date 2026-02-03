<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // database/migrations/xxxx_create_device_locations_table.php
        Schema::create('device_locations', function (Blueprint $table) {
            $table->id();
            $table->string('imei', 20);
            $table->dateTime('tracked_at');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->integer('speed')->default(0);
            $table->integer('course')->default(0);
            $table->boolean('ignition')->default(false);
            $table->boolean('gps_valid')->default(false);
            $table->timestamps();

            $table->index('imei');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_locations');
    }
};

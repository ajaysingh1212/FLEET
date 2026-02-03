<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
public function up()
{
    Schema::connection('sqlite_tracking')->create('live_locations', function (Blueprint $table) {
        $table->id();
        $table->string('imei')->unique();
        $table->double('latitude', 10, 6);
        $table->double('longitude', 10, 6);
        $table->integer('speed');
        $table->integer('course');
        $table->boolean('ignition');
        $table->boolean('gps_valid');
        $table->timestamp('tracked_at');
    });
}


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('live_locations');
    }
};

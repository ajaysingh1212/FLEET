<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LiveLocation extends Model
{
    protected $connection = 'sqlite_tracking';
    protected $table = 'device_locations';

    protected $fillable = [
        'imei','latitude','longitude','speed',
        'course','ignition','gps_valid','tracked_at'
    ];

    public $timestamps = false;
}

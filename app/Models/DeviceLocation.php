<?php

// app/Models/DeviceLocation.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceLocation extends Model
{
    protected $fillable = [
        'imei','tracked_at','latitude','longitude',
        'speed','course','ignition','gps_valid'
    ];
}

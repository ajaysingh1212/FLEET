<?php

// app/Models/Device.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    protected $fillable = ['imei','name'];

    public function locations(){
        return $this->hasMany(DeviceLocation::class, 'imei', 'imei');
    }
}

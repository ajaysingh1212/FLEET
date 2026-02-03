<?php

// app/Http/Controllers/MapController.php
namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\DeviceLocation;
use Carbon\Carbon;

class MapController extends Controller
{
    public function index()
    {
        $devices = Device::all();
        return view('welcome', compact('devices'));
    }

    public function latest($imei)
    {
        $loc = DeviceLocation::where('imei', $imei)
            ->latest('tracked_at')
            ->first();

        if (!$loc) {
            return response()->json(null);
        }
       
        return response()->json([
            'imei'       => $loc->imei,
            'latitude'   => (float) $loc->latitude,
            'longitude'  => (float) $loc->longitude,
            'speed'      => (int) $loc->speed,
            'course'     => (int) $loc->course,
            'ignition'   => (bool) $loc->ignition,
            'gps_valid'  => (bool) $loc->gps_valid,
            'tracked_at' => $loc->tracked_at,
        ]);
    }
    public function history($imei)
{
    $fromDate = request('from_date');
    $toDate   = request('to_date');
    $fromTime = request('from_time');
    $toTime   = request('to_time');

    $from = Carbon::parse($fromDate . ' ' . ($fromTime ?? '00:00:00'));
    $to   = Carbon::parse($toDate   . ' ' . ($toTime   ?? '23:59:59'));

    return DeviceLocation::where('imei', $imei)
        ->whereBetween('tracked_at', [$from, $to])
        ->orderBy('tracked_at')
        ->get([
            'latitude','longitude','tracked_at'
        ]);
}
}


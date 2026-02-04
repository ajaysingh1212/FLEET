<!DOCTYPE html>
<html>
<head>
    <title>Ajay Kumar</title>

    <style>
        body { margin:0; font-family: Arial, sans-serif; }

        #topbar {
            background:#111827;
            color:#fff;
            padding:10px;
            display:flex;
            gap:10px;
            align-items:center;
            flex-wrap: wrap;
        }

        #map { height: calc(100vh - 70px); width:100%; }

        .moving { color:#22c55e; }
        .idle { color:#f59e0b; }
        .stopped { color:#ef4444; }

        #historyBox {
            background:#f9fafb;
            padding:10px;
            display:none;
            gap:10px;
            align-items:center;
            border-bottom:1px solid #e5e7eb;
        }
    </style>
</head>
<body>

<!-- ================= TOP BAR ================= -->
<div id="topbar">
    <select id="device">
        @foreach($devices as $d)
            <option value="{{ $d->imei }}">{{ $d->imei }}</option>
        @endforeach
    </select>

    <button onclick="toggleHistory()">📜 History</button>

    <span>Speed: <b id="speed">--</b></span>
    <span>Status: <b id="status">--</b></span>
    <span>Ignition: <b id="ignition">--</b></span>
    <span>Last: <b id="time">--</b></span>
</div>

<!-- ================= HISTORY CONTROLS ================= -->
<div id="historyBox">
    <label>From Date:
        <input type="date" id="fromDate">
    </label>

    <label>From Time:
        <input type="time" id="fromTime">
    </label>

    <label>To Date:
        <input type="date" id="toDate">
    </label>

    <label>To Time:
        <input type="time" id="toTime">
    </label>

    <button onclick="loadHistory()">Show History</button>
    <button onclick="clearHistory()">Clear</button>
</div>

<div id="map"></div>

<script>
let map;
let marker = null;
let polyline = null;
let lastPos = null;
let liveTimer = null;

/* ================= MAP INIT ================= */
function initMap() {
    map = new google.maps.Map(document.getElementById("map"), {
        zoom: 16,
        center: { lat: 25.6089, lng: 85.1443 }
    });
    startLive();
}

/* ================= HISTORY TOGGLE ================= */
function toggleHistory(){
    const box = document.getElementById('historyBox');
    box.style.display = box.style.display === 'none' ? 'flex' : 'none';

    if(box.style.display === 'flex'){
        stopLive();
    } else {
        clearHistory();
        startLive();
    }
}

/* ================= LIVE TRACKING ================= */
function startLive(){
    stopLive();
    liveTimer = setInterval(updateLive, 3000);
}

function stopLive(){
    if(liveTimer) clearInterval(liveTimer);
}

/* ================= LIVE UPDATE ================= */
function updateLive(){
    const imei = document.getElementById('device').value;

    fetch(`/device/${imei}/latest`)
        .then(r => r.json())
        .then(d => {
            if(!d) return;

            const pos = { lat:d.latitude, lng:d.longitude };

            document.getElementById('speed').innerText = d.speed + ' km/h';
            document.getElementById('ignition').innerText = d.ignition ? 'ON':'OFF';
            document.getElementById('time').innerText = d.tracked_at;

            if(!marker){
                marker = new google.maps.Marker({
                    position: pos,
                    map: map,
                    icon:{
                        url:'https://cdn-icons-png.flaticon.com/512/744/744465.png',
                        scaledSize:new google.maps.Size(40,40)
                    }
                });
                map.setCenter(pos);
            } else if(lastPos){
                marker.setPosition(pos);
            }
            lastPos = pos;
        });
}

/* ================= LOAD HISTORY ================= */
function loadHistory(){
    const imei = document.getElementById('device').value;
    const fromDate = document.getElementById('fromDate').value;
    const fromTime = document.getElementById('fromTime').value;
    const toDate   = document.getElementById('toDate').value;
    const toTime   = document.getElementById('toTime').value;

    let url = `/device/${imei}/history?from_date=${fromDate}&to_date=${toDate}`;

    if(fromTime) url += `&from_time=${fromTime}`;
    if(toTime)   url += `&to_time=${toTime}`;

    fetch(url)
        .then(r=>r.json())
        .then(points=>{
            if(!points.length) return;

            clearHistory();

            const path = points.map(p => ({
                lat: parseFloat(p.latitude),
                lng: parseFloat(p.longitude)
            }));

            polyline = new google.maps.Polyline({
                path: path,
                geodesic: true,
                strokeColor: '#2563eb',
                strokeOpacity: 1.0,
                strokeWeight: 4,
                map: map
            });

            map.fitBounds(new google.maps.LatLngBounds(
                path[0],
                path[path.length-1]
            ));
        });
}

/* ================= CLEAR HISTORY ================= */
function clearHistory(){
    if(polyline){
        polyline.setMap(null);
        polyline = null;
    }
}
</script>

<script async
    src="https://maps.googleapis.com/maps/api/js?key=AIzaSyBgRXfXiK8KHfSnKtunSIpGpKNmLNGNUzM&callback=initMap">
</script>

</body>
</html>

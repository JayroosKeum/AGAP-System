<link rel="stylesheet"
href="https://unpkg.com/leaflet/dist/leaflet.css">

<script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>

<div
    id="map"
    style="height:500px;">
</div>

<script>

var map = L.map('map')
.setView([14.6507,121.1029],13);

L.tileLayer(
'https://tile.openstreetmap.org/{z}/{x}/{y}.png'
).addTo(map);

var marker;

map.on('click', function(e){

    if(marker)
    {
        map.removeLayer(marker);
    }

    marker = L.marker(
        e.latlng
    ).addTo(map);

    document.getElementById(
        'latitude'
    ).value = e.latlng.lat;

    document.getElementById(
        'longitude'
    ).value = e.latlng.lng;

});

</script>
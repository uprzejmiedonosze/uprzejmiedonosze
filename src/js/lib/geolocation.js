/* global google */

import mapboxgl from 'mapbox-gl'

let map // represents mapboxgl.Map

export function initMaps(lastLocation) {
  let center = [19.480311, 52.069321]
  if (lastLocation) {
    lastLocation = lastLocation.replace(/(\d+\.\d{6})\d+/g, '$1').split(",")
    if (lastLocation.length == 2 && !isNaN(lastLocation[0])) {
      center = lastLocation.reverse()
    }
  }

  const mapOptions = {
    container: 'locationPicker',
    center: center,
    zoom: 15,
    hash: false,
    language: 'pl',
    // maxBounds
    maxZoom: 16,
    minZoom: 6,
    scrollZoom: false,
    style: 'mapbox://styles/mapbox/outdoors-v12',
    cooperativeGestures: true,
    dragRotate: false
  }
  
  mapboxgl.accessToken = 'pk.eyJ1IjoidXByemVqbWllZG9ub3N6ZXQiLCJhIjoiY2xxc2VkbWU3NGthZzJrcnExOWxocGx3bSJ9.r1y7A6C--2S2psvKDJcpZw';
  map = new mapboxgl.Map(mapOptions)

  map.addControl(new mapboxgl.NavigationControl({
    showCompass: false,
    showZoom: true,
    visualizePitch: true
  }), 'top-left')

  map.addControl(new mapboxgl.GeolocateControl({
    positionOptions: { enableHighAccuracy: true },
    trackUserLocation: true,
    showUserHeading: true
  }), 'top-left')

  map.dragRotate.disable()
  map.touchZoomRotate.disableRotation()

  map.on('moveend', updateAddressDebounce)

  if($("#lokalizacja").val().trim() == 0)
    updateAddressDebounce()
}

let timeout
function updateAddressDebounce() {
  const { lat, lng } = map.getCenter()  
  clearTimeout(timeout);
  timeout = setTimeout(setAddressByLatLng.bind(this, lat, lng, 'map'), 1000);
}

export function setAddressByLatLng(lat, lng, from) {
  if (from === "picture" && map)
    map.setCenter([lng, lat])

  $("#address").val(JSON.stringify({}))

  $("a#geo").buttonMarkup({ icon: "clock" })
  if (from == "picture") {
    $("#lokalizacja").attr("placeholder", "(pobieram adres ze zdjęcia...)")
  } else {
    $("#lokalizacja").attr("placeholder", "(pobieram adres z mapy...)")
  }
  
  latLngToAddress(lat, lng, from)
}


async function latLngToAddress(lat, lng, from) {
  $("#addressHint").text("Podaj adres lub wskaż go na mapie");
  $("#addressHint").removeClass("hint");

  const address = await getMapBox(lat, lng)
$("#address").val(JSON.stringify(address))
  $("a#geo").buttonMarkup({ icon: "location" })
  $("#lokalizacja").removeClass("error")
  if (from == "picture") {
    $("#addressHint").text("Sprawdź automatycznie pobrany adres")
    $("#addressHint").addClass("hint")
  }
  const nominatim = await getNominatim(lat, lng, address.city)
  address.address = address.address || nominatim.address.address
  address.city = address.city || nominatim.address.city
  address.voivodeship = address.voivodeship || nominatim.address?.voivodeship
  address.postcode = address.postcode || nominatim.address?.postcode
  address.municipality = nominatim.address?.municipality
  address.county = nominatim.address?.county
  address.district = nominatim.address?.district

  $("#lokalizacja").val(address?.address || '')
  if (!address?.address?.match(/.+,.+/)) {
    $("a#geo").buttonMarkup({ icon: "alert" })
    $("#lokalizacja").addClass("error")
  }
  
  $("#address").val(JSON.stringify(address))
  if (nominatim.sm) {
    $("#smInfo").text(nominatim.sm.address[0])
    $("#smInfoHint").attr('title', nominatim.sm.hint)
    $("#smInfoHint").show()
  } else {
    $("#smInfo").text(`(brak SM dla ${address.city})`)
    $("#smInfoHint").attr('title', '')
    $("#smInfoHint").hide()
  }
}

async function getNominatim(lat, lng, city) {
  const response = await fetch(`https://apistaging.uprzejmiedonosze.net/geo/${lat},${lng}/n?city=` + encodeURIComponent(city))
  return await response.json()
}

async function getMapBox(lat, lng) {
  const response = await fetch(`https://apistaging.uprzejmiedonosze.net/geo/${lat},${lng}/m`)
  const mapbox = await response.json()
  const address = mapbox.address || {}
  address.latlng = `${lat.toFixed(4)},${lng.toFixed(4)}`

  return address
}
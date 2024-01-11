/* global google */

import mapboxgl from 'mapbox-gl'

let map // represents mapboxgl.Map
let stopAgresji = false

export function initMaps(lastLocation, _stopAgresji) {
  stopAgresji = _stopAgresji ?? false
  const $input = $("#lokalizacja")

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
    zoom: 16,
    hash: false,
    language: 'pl',
    // maxBounds
    maxZoom: 17,
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

  if($input.val().trim() == 0)
    updateAddressDebounce()
}

let timeout
let running = false
function updateAddressDebounce() {
  if (running) return
  running = true
  const { lat, lng } = map.getCenter()  
  clearTimeout(timeout);
  timeout = setTimeout(setAddressByLatLng.bind(this, lat, lng, 'map'), 1000);
}

export function setAddressByLatLng(lat, lng, from) {
  const $input = $("#lokalizacja")
  const $geoIcon = $("#geo")
  const $address = $("#address")

  if (from === "picture" && map)
    map.setCenter([lng, lat])

  $address.val(JSON.stringify({}))

  $geoIcon.buttonMarkup({ icon: "clock" })
  if (from == "picture") {
    $input.attr("placeholder", "(pobieram adres ze zdjęcia...)")
  } else {
    $input.attr("placeholder", "(pobieram adres z mapy...)")
  }
  latLngToAddress(lat, lng, from)
}


async function latLngToAddress(lat, lng, from) {
  const $addressHint = $("#addressHint")
  const $address = $("#address")
  const $input = $("#lokalizacja")
  const $geoIcon = $("#geo")
  const $sm = $("#smInfo")
  const $smHint = $("#smInfoHint")

  $addressHint.text("Podaj adres lub wskaż go na mapie")
  $addressHint.removeClass("hint")

  const geoError = () => {
    $geoIcon.buttonMarkup({ icon: "alert" })
    $sm.text('')
    $smHint.attr('title', '')
  }

  const geoSuccess = (address) => {
    $address.val(JSON.stringify(address))
    $input.val(address?.address || '')
    $geoIcon.buttonMarkup({ icon: "location" })
    $geoIcon.removeClass("error")
    if (!address?.address?.match(/.+,.+/)) {
      $geoIcon.buttonMarkup({ icon: "alert" })
      $input.addClass("error")
    }
    if (from == "picture") {
      $addressHint.text("Sprawdź automatycznie pobrany adres")
      $input.addClass("hint")
    }
  }

  const address = await getMapBox(lat, lng)

  if (address.error) geoError()
  else geoSuccess(address)

  const nominatim = await getNominatim(lat, lng)
  if (nominatim.error) {
    running = false
    return
  }
  address.address = address.address || nominatim.address.address
  address.city = address.city || nominatim.address.city
  address.voivodeship = address.voivodeship || nominatim.address?.voivodeship
  address.postcode = address.postcode || nominatim.address?.postcode
  address.municipality = nominatim.address?.municipality
  address.county = nominatim.address?.county
  address.district = nominatim.address?.district
  
  geoSuccess(address)

  $sm.text('')
  $smHint.attr('title', '')
  if (stopAgresji) {
    $sm.text(nominatim.sa.address[0])
    $smHint.attr('title', nominatim.sa.hint ?? '')
  } else if (nominatim.sm?.email) {
    $sm.text(nominatim.sm.address[0])
    $smHint.attr('title', nominatim.sm.hint ?? '')
  }
  $smHint.css('visibility', ($sm.text() == '') ? 'none': 'visible')
  running = false
}

async function getNominatim(lat, lng) {
  const response = await fetch(`https://apistaging.uprzejmiedonosze.net/geo/${lat},${lng}/n`)
  return await response.json()
}

async function getMapBox(lat, lng) {
  const response = await fetch(`https://apistaging.uprzejmiedonosze.net/geo/${lat},${lng}/m`)
  const mapbox = await response.json()
  if (mapbox.error) return mapbox

  const address = mapbox.address || {}
  address.latlng = `${lat.toFixed(4)},${lng.toFixed(4)}`

  return address
}
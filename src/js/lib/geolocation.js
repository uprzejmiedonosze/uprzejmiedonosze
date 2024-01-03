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

  $("#lokalizacja").val(address?.address || '')
  if (!address?.address?.match(/.+\d.+/)) {
    $("a#geo").buttonMarkup({ icon: "alert" })
    $("#lokalizacja").addClass("error")
    return
  }
  $("#address").val(JSON.stringify(address))
  $("a#geo").buttonMarkup({ icon: "location" })
  if (from == "picture") {
    $("#addressHint").text("Sprawdź automatycznie pobrany adres")
    $("#addressHint").addClass("hint")
  }
  const nominatim = await getNominatim(lat, lng)
  address.city = address.city || nominatim.city
  address.voivodeship = address.voivodeship || nominatim.voivodeship
  address.postcode = address.postcode || postcode
  address.municipality = nominatim.municipality
  address.county = nominatim.county
  address.district = nominatim.district
  $("#address").val(JSON.stringify(address))
}

function encodeGetParams(p) {
  return Object.entries(p).map(kv => kv.map(encodeURIComponent).join("=")).join("&")
}

const headers = {
  "Accept-Language": "pl"
}

async function getNominatim(lat, lng) {
  const nominatimParams = {
    lat: lat,
    lon: lng,
    format: 'jsonv2',
    addressdetails: 1
  }
  let response = await fetch('https://nominatim.openstreetmap.org/reverse?' + encodeGetParams(nominatimParams), {
    headers: headers
  })
  response = await response.json()
  let { borough,
    suburb,
    quarter,
    neighbourhood,
    state,
    village,
    county,
    municipality,
    city,
    postcode } = response.address
  
  return {
    voivodeship: state.replace('województwo ', ''),
    district: suburb || borough || quarter || neighbourhood || '',
    county: county || `gmina ${city}`,
    municipality: municipality || `powiat ${city}`,
    city: city || village,
    municipality,
    postcode
  }
}

async function getMapBox(lat, lng) {
  const maxBoxParams = {
    country: 'pl',
    limit: 1,
    types: 'address,place,district,postcode,region,neighborhood',
    language: 'pl',
    longitude: lng,
    latitude: lat,
    access_token: 'pk.eyJ1IjoidXByemVqbWllZG9ub3N6ZXQiLCJhIjoiY2xxc2VkbWU3NGthZzJrcnExOWxocGx3bSJ9.r1y7A6C--2S2psvKDJcpZw'
  }
  let response = await fetch('https://api.mapbox.com/search/geocode/v6/reverse?' + encodeGetParams(maxBoxParams), {
    headers: headers
  })
  response = await response.json()
  
  if (!response.features.length)
    return null

  const mapbox = response.features[0].properties
  const context = mapbox.context

  let voivodeship = context.region?.name?.replace('województwo ', '')
  let city = context.place?.name || ''
  if (city) scity = `, ${city}`
  else scity = ''

  return {
    address: address = `${mapbox.name}${scity}`,
    city,
    voivodeship,
    postcode: context.postcode?.name,
    latlng: `${lat.toFixed(4)},${lng.toFixed(4)}`
  }
}
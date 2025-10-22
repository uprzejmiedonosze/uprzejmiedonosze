import $ from "jquery"

import mapboxgl from 'mapbox-gl'
import Api from './Api'
import { error } from "./toast"

let map // represents mapboxgl.Map
let stopAgresji = false

export function initMaps(lastLocation, _stopAgresji) {
  stopAgresji = _stopAgresji ?? false
  const $input = $("#lokalizacja")
  $input.removeClass()
  $input.addClass("clock")

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
  try {
    map = new mapboxgl.Map(mapOptions)
  } catch(e) {
    error(e.getMessage())
  }
  

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

  if($input?.val()?.trim()?.length == 0)
    setAddressByLatLng(center[1], center[0], 'init');

  map.on('moveend', updateAddressDebounce)

  return map
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
  geoLoading()
  const $address = $("#address")

  if (from === "picture" && map)
    map.setCenter([lng, lat])

  $address.val(JSON.stringify({}))
  latLngToAddress(lat, lng, from)
}

function geoLoading(from) {
  const $input = $("#lokalizacja")
  $input.removeClass()
  $input.addClass("clock")

  $("#form-submit").addClass("disabled");
  if (from == "picture") {
    $input.attr("placeholder", "(pobieram adres ze zdjęcia...)")
  } else {
    $input.attr("placeholder", "(pobieram adres z mapy...)")
  }
}

function setSM(sm, hint) {
  const $sm = $("#smInfo")
  const $smHint = $("#smInfoHint")

  sm = sm ? `Rejon: ${sm}`: ''
  $sm.text(sm)
  $smHint.html(hint ?? '')
}

async function latLngToAddress(lat, lng, from) {
  const $addressHint = $("#addressHint")
  const $address = $("#address")
  const $input = $("#lokalizacja")

  $addressHint.text("Podaj adres lub wskaż go na mapie")
  $addressHint.removeClass("hint")

  const geoError = () => {
    $input.removeClass()
    $input.addClass("alert")
    setSM()
  }

  const geoSuccess = (address) => {
    $address.val(JSON.stringify(address))
    $input.val(address?.address || '')
    $input.removeClass()
    if (!address?.address?.match(/.+,.+/)) {
      $input.removeClass()
      $input.addClass("error")
    }
    if (from == "picture") {
      $addressHint.text("Sprawdź automatycznie pobrany adres")
      $input.addClass("hint")
    }
    $("#form-submit").removeClass("disabled");
  }

  let address = {
    lat,
    lng
  }

  try {
    const mapbox = await getMapBox(lat, lng)
    address = {...address, ...mapbox.address}
    geoSuccess(address)
  } catch (_e) {
    geoError()
  }

  let nominatim = {}
  try {
    nominatim = await getNominatim(lat, lng)
  } catch (_e) {
    running = false
    setSM()
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

  if (stopAgresji) {
    setSM(nominatim.sa.address[0], nominatim.sa.hint ?? '')
  } else if (nominatim.sm?.email) {
    setSM(nominatim.sm.address[0], nominatim.sm.hint ?? '')
  }
  running = false
}

async function getNominatim(lat, lng) {
  const api = new Api(`/api/geo/${lat},${lng}/n`, true)
  return await api.getJson()
}

async function getMapBox(lat, lng) {
  const api = new Api(`/api/geo/${lat},${lng}/m`, true)
  return await api.getJson()
}

import mapboxgl from 'mapbox-gl'
import Api from './Api'
import { error } from "./toast"

let map // represents mapboxgl.Map
let stopAgresji = false

export function initMaps(lastLocation, _stopAgresji) {
  stopAgresji = _stopAgresji ?? false
  const input = /** @type {HTMLInputElement} */ (document.getElementById("lokalizacja"))
  if (input) {
    input.className = "clock"
  }

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
    trackUserLocation: false,
    showUserHeading: false
  }), 'top-left')

  map.dragRotate.disable()
  map.touchZoomRotate.disableRotation()

  if (input && (!input.value || input.value.trim().length == 0))
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
  const address = /** @type {HTMLInputElement} */ (document.getElementById("address"))

  if (from === "picture" && map)
    map.setCenter([lng, lat])

  if (address) address.value = JSON.stringify({})
  latLngToAddress(lat, lng, from)
}

function geoLoading(from) {
  const input = /** @type {HTMLInputElement} */ (document.getElementById("lokalizacja"))
  const formSubmit = document.getElementById("form-submit")
  
  if (input) {
    input.className = "clock"
  }

  if (formSubmit) formSubmit.classList.add("disabled")
  
  if (input) {
    if (from == "picture") {
      input.placeholder = "(pobieram adres ze zdjęcia...)"
    } else {
      input.placeholder = "(pobieram adres z mapy...)"
    }
  }
}

function setSM(sm, hint) {
  const smInfo = document.getElementById("smInfo")
  const smHint = document.getElementById("smInfoHint")

  sm = sm ? `Rejon: ${sm}`: ''
  if (smInfo) smInfo.textContent = sm
  if (smHint) smHint.innerHTML = hint ?? ''
}

async function latLngToAddress(lat, lng, from) {
  const addressHint = document.getElementById("addressHint")
  const address = /** @type {HTMLInputElement} */ (document.getElementById("address"))
  const input = /** @type {HTMLInputElement} */ (document.getElementById("lokalizacja"))

  if (addressHint) {
    addressHint.textContent = "Podaj adres lub wskaż go na mapie"
    addressHint.classList.remove("hint")
  }

  const geoError = () => {
    if (input) {
      input.className = "alert"
    }
    setSM()
  }

  const geoSuccess = (addressData) => {
    if (address) address.value = JSON.stringify(addressData)
    if (input) {
      input.value = addressData?.address || ''
      input.className = ""
      if (!addressData?.address?.match(/.+,.+/)) {
        input.classList.add("error")
      }
    }
    if (from == "picture") {
      if (addressHint) addressHint.textContent = "Sprawdź automatycznie pobrany adres"
      if (input) input.classList.add("hint")
    }
    const formSubmit = document.getElementById("form-submit")
    if (formSubmit) formSubmit.classList.remove("disabled")
  }

  let addressData = {
    lat,
    lng
  }

  try {
    const mapbox = await getMapBox(lat, lng)
    addressData = {...addressData, ...mapbox.address}
    geoSuccess(addressData)
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

  addressData.address = addressData.address || nominatim.address.address
  addressData.city = addressData.city || nominatim.address.city
  addressData.voivodeship = addressData.voivodeship || nominatim.address?.voivodeship
  addressData.postcode = addressData.postcode || nominatim.address?.postcode
  addressData.municipality = nominatim.address?.municipality
  addressData.county = nominatim.address?.county
  addressData.district = nominatim.address?.district
  
  geoSuccess(addressData)

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

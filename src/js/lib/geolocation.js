import mapboxgl from 'mapbox-gl'
import Api from './Api'
import { error } from "./toast"

let map // represents mapboxgl.Map
let stopAgresji = false
let lastNominatim = null
let smUnknown = false

export function isSMUnknown() { return smUnknown }

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

const UNIT_FALLBACK_NAME = {
  sm: 'Straż Miejska',
  sa: 'Policja'
}

function setUnitLabel(unit, name, hint) {
  const nameEl = document.getElementById(`unit-${unit}-name`)
  const hintEl = document.getElementById(`unit-${unit}-hint`)
  if (nameEl) nameEl.textContent = name ? `${name}` : UNIT_FALLBACK_NAME[unit]
  if (hintEl) hintEl.innerHTML = hint ?? ''
}

function clearUnitLabels() {
  setUnitLabel('sm', '', '')
  setUnitLabel('sa', '', '')
}

// Shows the actual unit name on both options; disables SM radio when no SM
// exists for this location and switches to Police automatically.
// fromGeo=true fires geo:smUpdate so on-load.js can re-evaluate radio state;
// omit it when called from setStopAgresji (user toggle) to avoid recursion.
function renderSM(fromGeo = false) {
  if (!lastNominatim) return
  smUnknown = !lastNominatim.sm?.email
  const smName = smUnknown ? '' : lastNominatim.sm.short
  const saName = lastNominatim.sa?.short ?? ''
  const city = lastNominatim.address?.city ?? ''
  const smHint = smUnknown
    ? `W miejscowości ${city} nie powołano SM`
    : (!stopAgresji ? (lastNominatim.sm?.hint ?? '') : '')
  setUnitLabel('sm', smName, smHint)
  setUnitLabel('sa', saName, stopAgresji ? (lastNominatim.sa?.hint ?? '') : '')
  if (fromGeo) document.dispatchEvent(new CustomEvent('geo:smUpdate'))
}

// Lets the new-application form switch SM/Policja ad hoc without re-fetching
// the address (both variants are already present in the cached nominatim response).
export function setStopAgresji(value) {
  stopAgresji = value
  renderSM() // fromGeo=false: labels only, no geo:smUpdate
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
    clearUnitLabels()
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
    lastNominatim = nominatim
  } catch (_e) {
    running = false
    lastNominatim = null
    smUnknown = false
    clearUnitLabels()
    document.dispatchEvent(new CustomEvent('geo:smUpdate'))
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

  renderSM(true) // fromGeo=true: also fires geo:smUpdate
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

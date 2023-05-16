/* global google */

import { Loader } from "@googlemaps/js-api-loader";
import LocationPicker from "location-picker";

let autocomplete;

let locationP;
var initialLocation = [];

function getGoogleLoader() {
  return new Loader({
    apiKey: "AIzaSyC2vVIN-noxOw_7mPMvkb-AWwOk6qK1OJ8",
    version: "weekly",
    language: "pl",
    libraries: ["places"]
  });
}

export function initAutocompleteRegister() {
  const loader = getGoogleLoader();

  loader.loadCallback((e) => {
    if (e) return console.error(e);
    initAutocomplete(false, "address", google);
  });
}

export function initAutocompleteNewApplication() {
  const loader = getGoogleLoader();

  loader.loadCallback((_e) => {
    initAutocomplete(true, "lokalizacja", google);

    locationP = new LocationPicker(
      "locationPicker",
      {
        setCurrentPosition: false
      },
      {
        disableDefaultUI: true,
        scrollwheel: false,
        zoomControl: true,
        controlSize: 25,
        mapTypeId: google.maps.MapTypeId.SATTELITE,
        gestureHandling: "cooperative",
        estriction: new google.maps.LatLngBounds(
          new google.maps.LatLng(54.8, 14),
          new google.maps.LatLng(49, 24)
        ),
        zoom: 17,
        minZoom: 6,
        maxZoom: 19
      }
    );
    if (initialLocation.length == 2) {
      locationP.setLocation(initialLocation[0], initialLocation[1]);
    } else {
      locationP.setLocation(52.069321, 19.480311);
    }
    google.maps.event.addListener(locationP.map, "idle", function () {
      var location = locationP.getMarkerPosition();
      setAddressByLatLng(location.lat, location.lng, "picker");
    });
  });
}

export const initAutocomplete = function (trigger_change, inputId, google) {
  autocomplete = new google.maps.places.Autocomplete(
    document.getElementById(inputId),
    {
      types: ["address"],
      componentRestrictions: { country: "pl" }
    }
  );
  if (trigger_change) {
    autocomplete.addListener("place_changed", function () {
      const place = autocomplete.getPlace();
      if (!place) return;
      setAddressByPlace(place);
      const latlng = locationToLatLng(place);
      if (latlng[0] && latlng[1]) {
        $("#latlng").val(latlng.join(","));
        locationP.setLocation(latlng[0], latlng[1]);
      }
    });
  }
};

const locationToLatLng = function (place) {
  return typeof place?.geometry?.location?.lat == "function"
    ? [place.geometry.location.lat(), place.geometry.location.lng()]
    : [place?.geometry?.location?.lat, place?.geometry?.location?.lng];
};

export function setAddressByLatLngString(latlng) {
  if (latlng) {
    latlng = latlng.replace(/(\d+\.\d{6})\d+/g, '$1')
    const ll = latlng.split(",");
    if (ll.length == 2 && !isNaN(ll[0])) {
      initialLocation = ll;
    }
  }
}

export function setAddressByLatLng(lat, lng, from) {

  // init|picker|picture
  if (from !== "picker" && locationP) {
    locationP.setLocation(lat, lng);
  }

  if (from == "init") {
    return;
  }

  $("a#geo").buttonMarkup({ icon: "clock" });
  if (from == "picture") {
    $("#lokalizacja").attr("placeholder", "(pobieram adres ze zdjęcia...)");
  } else {
    $("#lokalizacja").attr("placeholder", "(pobieram adres z mapy...)");
  }
  $("#latlng").val(lat + "," + lng);

  $.post("/api/api.html", { action: "geoToAddress", lat: lat, lng: lng })
    .done(function (result) {
      $("#addressHint").text("Podaj adres lub wskaż go na mapie");
      $("#addressHint").removeClass("hint");
      if (result) {
        setAddressByPlace(result, from);
        if (from == "picture") {
          $("#addressHint").text("Sprawdź automatycznie pobrany adres");
          $("#addressHint").addClass("hint");
        }
      }
      $("a#geo").buttonMarkup({ icon: "location" });
    })
    .fail(function (e) {
      console.log(e)
      $("a#geo").buttonMarkup({ icon: "alert" });
      $("#lokalizacja").addClass("error");
    });

  $("#lokalizacja").attr("placeholder", "(podaj adres lub wskaż go na mapie)");
}

function setAddressByPlace(place) {
  if(!place) return
  if(!place.formatted_address) return
  const formatted_address = place.formatted_address
    .replace(", Polska", "")
    .replace(/\d\d-\d\d\d\s/, "")
    .replace(/\/\d+[a-zA-Z]?, /, ', ');
  const voivodeship = place?.address_components
    ?.filter(e => e.types.indexOf("administrative_area_level_1") == 0)
    ?.shift()?.long_name?.replace("Województwo ", "");
  const country = place?.address_components
    ?.filter(e => e.types.indexOf("country") == 0)?.shift()?.long_name;
  const city = place?.address_components
    ?.filter(e => e.types.indexOf("locality") == 0)?.shift()?.long_name;
  const district = place?.address_components
    ?.filter(e => e.types.indexOf("sublocality_level_1") >= 0)?.shift()?.short_name;
  
  $("#lokalizacja").val(formatted_address || "");
  voivodeship && $("#administrative_area_level_1").val(voivodeship || "");
  country && $("#country").val(country || "");
  city && $("#locality").val(city || "");
  district && $("#district").val(district || "");

  $("a#geo").buttonMarkup({ icon: "check" });
  $("#lokalizacja").removeClass("error");
}

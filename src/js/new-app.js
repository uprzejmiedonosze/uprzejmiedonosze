/* global ga */

import {
  initAutocompleteNewApplication,
  setAddressByLatLngString
} from "./lib/geolocation";
import { initHandlers } from "./new-app/on-load";

const currentScript = document.currentScript;

$(document).on("pageshow", function () {
  if (!$(".new-application").length) return;

  initHandlers();
  initAutocompleteNewApplication();
  if (currentScript) setAddressByLatLngString(currentScript.getAttribute("last-location"));
  (typeof ga == 'function') && ga("send", "event", {
    eventCategory: "pageshow",
    eventAction: "nowe-zgloszenie"
  });
});

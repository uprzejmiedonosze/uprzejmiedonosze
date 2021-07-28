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
  if (currentScript) setAddressByLatLngString(currentScript.getAttribute("last-location"));
  ga("send", "event", {
    eventCategory: "pageshow",
    eventAction: "nowe-zgloszenie"
  });
  initAutocompleteNewApplication();
});

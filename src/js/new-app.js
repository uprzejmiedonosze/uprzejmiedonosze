/* global ga */

import * as Sentry from "@sentry/browser"
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
  initAutocompleteNewApplication();

  Sentry.setTag("appId", $("#applicationId").val());

  (typeof ga == 'function') && ga("send", "event", {
    eventCategory: "pageshow",
    eventAction: "nowe-zgloszenie"
  });
});

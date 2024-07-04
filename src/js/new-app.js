/* global ga */

import * as Sentry from "@sentry/browser"
import { initMaps } from "./lib/geolocation";
import { initHandlers } from "./new-app/on-load";
import { repositionCarImage } from "./new-app/images";

const currentScript = document.currentScript;

$(document).on("pageshow", function () {
  if (!$(".new-application").length) return;

  const map = initMaps(currentScript?.getAttribute("last-location"), currentScript?.getAttribute("stop-agresji"))
  initHandlers(map)

  if (currentScript?.getAttribute("vehicleBox")) {
    const vehicleBox = JSON.parse(currentScript.getAttribute("vehicleBox"))
    const imageWidth = currentScript?.getAttribute("imageWidth")
    const imageHeight = currentScript?.getAttribute("imageHeight")
    repositionCarImage(vehicleBox, imageWidth, imageHeight)
  }

  Sentry.setTag("appId", $("#applicationId").val());

  (typeof ga == 'function') && ga("send", "event", {
    eventCategory: "pageshow",
    eventAction: "nowe-zgloszenie"
  });
});

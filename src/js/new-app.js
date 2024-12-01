import $ from "jquery"

import * as Sentry from "@sentry/browser"
import { initMaps } from "./lib/geolocation";
import { initHandlers } from "./new-app/on-load";
import { repositionCarImage } from "./new-app/images";

const currentScript = document.currentScript;

document.addEventListener("DOMContentLoaded", () => {
  if (!$(".new-application").length) return;

  const map = initMaps(currentScript?.getAttribute("last-location"), currentScript?.getAttribute("stop-agresji"))
  initHandlers(map)

  if (currentScript?.getAttribute("data-vehiclebox-x")) {
    const vehicleBox = {
      x: currentScript.getAttribute("data-vehiclebox-x"),
      y: currentScript.getAttribute("data-vehiclebox-y"),
      width: currentScript.getAttribute("data-vehiclebox-width"),
      height: currentScript.getAttribute("data-vehiclebox-height")
    }
    const imageWidth = currentScript?.getAttribute("data-image-width")
    const imageHeight = currentScript?.getAttribute("data-image-height")
    repositionCarImage(vehicleBox, imageWidth, imageHeight)
  }

  Sentry.setTag("appId", $(".new-application #applicationId").val()?.toString());

  // @ts-ignore
  (typeof ga == 'function') && ga("send", "event", {
    eventCategory: "pageshow",
    eventAction: "nowe-zgloszenie"
  });
});

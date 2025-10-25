import * as Sentry from "@sentry/browser"
import { initMaps } from "./lib/geolocation";
import { initHandlers } from "./new-app/on-load";
import { removeFile, repositionCarImage } from "./new-app/images";
import { updateRecydywa } from "./new-app/recydywa";

const currentScript = document.currentScript;

document.addEventListener("DOMContentLoaded", () => {
  if (!document.querySelector(".new-application")) return;

  const map = initMaps(currentScript?.getAttribute("last-location"), currentScript?.getAttribute("stop-agresji"))
  initHandlers(map)

  document.querySelector('.button.remove')?.addEventListener('click', function () {
    removeFile('thirdImage')
  })

  if (currentScript?.getAttribute("data-vehiclebox-x")) {
    const vehicleBox = {
      x: currentScript.getAttribute("data-vehiclebox-x"),
      y: currentScript.getAttribute("data-vehiclebox-y"),
      width: currentScript.getAttribute("data-vehiclebox-width"),
      height: currentScript.getAttribute("data-vehiclebox-height")
    }
    const imageWidthAttr = currentScript?.getAttribute("data-image-width")
    const imageWidth = imageWidthAttr ? parseInt(imageWidthAttr) : 0
    const imageHeightAttr = currentScript?.getAttribute("data-image-height")
    const imageHeight = imageHeightAttr ? parseInt(imageHeightAttr) : 0
    repositionCarImage(vehicleBox, imageWidth, imageHeight)
  }

  const appIdElement = /** @type {HTMLInputElement} */ (document.querySelector(".new-application #applicationId"))
  const appId = appIdElement?.value?.toString()

  Sentry.setTag("appId", appId);

  const plateIdElement = /** @type {HTMLInputElement} */ (document.getElementById("plateId"))
  const plateId = plateIdElement?.value
  if (plateId) {
    updateRecydywa(appId);
  }

  // @ts-ignore
  (typeof ga == 'function') && ga("send", "event", {
    eventCategory: "pageshow",
    eventAction: "nowe-zgloszenie"
  });
});

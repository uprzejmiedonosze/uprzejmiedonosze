import $ from "jquery"

import * as Sentry from "@sentry/browser"
import { initMaps } from "./lib/geolocation";
import { initHandlers } from "./new-app/on-load";
import { removeFile, repositionCarImage } from "./new-app/images";
import { updateRecydywa } from "./new-app/recydywa";

const currentScript = document.currentScript;

document.addEventListener("DOMContentLoaded", () => {
  if (!$(".new-application").length) return;

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
    const imageWidth = currentScript?.getAttribute("data-image-width") || 0
    const imageHeight = currentScript?.getAttribute("data-image-height")
    repositionCarImage(vehicleBox, imageWidth, imageHeight)
  }

  Sentry.setTag("appId", $(".new-application #applicationId").val()?.toString());

  const plateId = $("#plateId")?.val()
  if (plateId) {
    const appId = $(".new-application #applicationId").val();
    updateRecydywa(appId);
  }

  // @ts-ignore
  (typeof ga == 'function') && ga("send", "event", {
    eventCategory: "pageshow",
    eventAction: "nowe-zgloszenie"
  });
});

import $ from "jquery"

document.addEventListener("DOMContentLoaded", () => {
  if (!$(".start-page").length) return;
  // @ts-ignore
  (typeof ga == 'function') && ga("send", "event", { eventCategory: "pageshow", eventAction: "start" });
});

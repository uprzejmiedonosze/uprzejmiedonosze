import $ from "jquery";

document.addEventListener("DOMContentLoaded", () => {
  if (!$(".mainPage").length) return;
  // @ts-ignore
  (typeof ga == 'function') && ga("send", "event", { eventCategory: "pageshow", eventAction: "index" });
});

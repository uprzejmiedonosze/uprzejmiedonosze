import $ from "jquery"

document.addEventListener("DOMContentLoaded", () => {
  if (!$(".dziekujemy").length) return;
  // @ts-ignore
  (typeof ga == 'function') && ga("send", "event", { eventCategory: "pageshow", eventAction: "dziekujemy" });
});

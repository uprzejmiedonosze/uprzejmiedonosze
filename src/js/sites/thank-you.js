/* global ga */

$(document).on("pageshow", function () {
  if (!$(".dziekujemy").length) return;
  // @ts-ignore
  (typeof ga == 'function') && ga("send", "event", { eventCategory: "pageshow", eventAction: "dziekujemy" });
});

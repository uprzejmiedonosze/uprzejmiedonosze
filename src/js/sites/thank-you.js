/* global ga */

$(document).on("pageshow", function () {
  if (!$(".dziekujemy").length) return;
  (typeof ga == 'function') && ga("send", "event", { eventCategory: "pageshow", eventAction: "dziekujemy" });
});

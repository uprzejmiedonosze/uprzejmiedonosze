/* global ga */

$(document).on("pageshow", function () {
  if (!$(".start-page").length) return;
  (typeof ga == 'function') && ga("send", "event", { eventCategory: "pageshow", eventAction: "start" });
});

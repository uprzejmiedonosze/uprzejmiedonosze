/* global ga */

$(document).on("pageshow", function () {
  if (!$(".mainPage").length) return;
  (typeof ga == 'function') && ga("send", "event", { eventCategory: "pageshow", eventAction: "index" });
});

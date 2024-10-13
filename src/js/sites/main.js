/* global ga */

$(document).on("pageshow", function () {
  if (!$(".mainPage").length) return;
  // @ts-ignore
  (typeof ga == 'function') && ga("send", "event", { eventCategory: "pageshow", eventAction: "index" });
});

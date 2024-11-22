import $ from "jquery"

$(document).on("pageshow", function () {
  if (!$(".start-page").length) return;
  // @ts-ignore
  (typeof ga == 'function') && ga("send", "event", { eventCategory: "pageshow", eventAction: "start" });
});

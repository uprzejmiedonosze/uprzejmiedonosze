/* global ga */

$(document).on("pageshow", function () {
  if (!$(".confirm-application").length) return;
  ga("send", "event", { eventCategory: "pageshow", eventAction: "potwierdz" });

  setTimeout(function () {
    $("a.confirm-send-button").removeClass('ui-disabled')
  }, 1500);
});

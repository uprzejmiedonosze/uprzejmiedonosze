/* global ga */

$(document).on("pageshow", function () {
  if (!$(".confirm-application").length) return;
  // @ts-ignore
  (typeof ga == 'function') && ga("send", "event", { eventCategory: "pageshow", eventAction: "potwierdz" });

  setTimeout(function () {
    $("a.confirm-send-button").removeClass('ui-disabled')
  }, 1500);
});


function confirmApplication() {
  $('#form').submit();
  $('.confirm-save-button').addClass('ui-disabled')
}

window.confirmApplication = confirmApplication;
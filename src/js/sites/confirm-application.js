/* global ga */

$(document).on("pageshow", function () {
  if (!$(".confirm-application").length) return;
  (typeof ga == 'function') && ga("send", "event", { eventCategory: "pageshow", eventAction: "potwierdz" });

  $('img.mapImage').load(() =>{
    $("a.confirm-send-button").removeClass('ui-disabled')
  })
});


function confirmApplication() {
  $('#form').submit();
  $('.confirm-save-button').addClass('ui-disabled')
  $(".confirm-send-button").addClass('ui-disabled')
}

window.confirmApplication = confirmApplication;
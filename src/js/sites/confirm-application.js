import $ from "jquery"

document.addEventListener("DOMContentLoaded", () => {
  if (!$(".confirm-application").length) return;
  // @ts-ignore
  (typeof ga == 'function') && ga("send", "event", { eventCategory: "pageshow", eventAction: "potwierdz" });

  setTimeout(function () {
    $("a.confirm-send-button").removeClass('disabled')
  }, 1500);
});


function confirmApplication() {
  $('#form').submit();
  $('.confirm-save-button').addClass('disabled')
}

window.confirmApplication = confirmApplication;
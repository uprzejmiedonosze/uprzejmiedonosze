import $ from "jquery"

document.addEventListener("DOMContentLoaded", () => {
  if (!$(".confirm-application").length) return;

  const appIdElement = document.getElementById("applicationId")
  let appId = null
  if (appIdElement && 'value' in appIdElement) {
    appId = appIdElement.value
  }

  // @ts-ignore
  (typeof umami == 'object') && umami.track("set-status", {
    appId
  });

  setTimeout(function () {
    $("a.confirm-send-button").removeClass('disabled')
  }, 1500);
});


function confirmApplication() {
  $('#form').submit();
  $('.confirm-save-button').addClass('disabled')
}

window.confirmApplication = confirmApplication;
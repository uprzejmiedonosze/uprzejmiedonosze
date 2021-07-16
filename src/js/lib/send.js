/* global ga */

import { _updateStatus } from "./status";

window._send = function (appId) {
  $(`#${appId} .status-confirmed-waiting`).addClass("ui-disabled");

  $.mobile.loading("show", { text: "Wysyłam...", textVisible: true });

  $.post("/api/api.html", { id: appId, action: "send" }, function (msg) {
    _updateStatus(appId, msg.status);
    $.mobile.loading("hide");
    showMessage("Wysłane", 1500);
    ga("send", "event", { eventCategory: "js", eventAction: "sendViaAPI" });
    if ($(".dziekujemy").length) {
      $(".whatNext").hide();
      $(".whatNextDone").show();
    }
  }).fail(function (e) {
    $.mobile.loading("hide");
    const message = e.responseJSON ? e.responseJSON.message : e.statusText;
    showMessage("Nie udało się wysłać zgłoszenia! " + message, 4000);
    ga("send", "event", {
      eventCategory: "js-error",
      eventAction: "sendViaAPI"
    });
  });
};

function showMessage(msg, timeout) {
  $.mobile.loading("show", { text: msg, textVisible: true, textonly: true });
  window.setTimeout(function () {
    $.mobile.loading("hide");
  }, timeout);
}

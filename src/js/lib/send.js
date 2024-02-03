/* global ga */

import * as Sentry from "@sentry/browser";

import { _updateStatus } from "./status";

window.sendApplication = function (appId) {
  $(`#${appId} .status-confirmed-waiting`).addClass("ui-disabled");
  $('.ui-btn-right').addClass("ui-disabled");

  $.mobile.loading("show", { text: "Wysyłam...", textVisible: true });

  $.post("/api/api.html", { id: appId, action: "send" }, function (msg) {
    _updateStatus(appId, msg.status);
    $.mobile.loading("hide");
    showMessage("<p>Wysłane</p>", 1500);
    if ($(".dziekujemy").length) {
      $(".whatNext").hide();
      $(".afterSend").show();
    }
    $('.ui-btn-right').removeClass("ui-disabled");
    (typeof ga == 'function') && ga("send", "event", { eventCategory: "js", eventAction: "sendViaAPI" });
  }).fail(function (e) {
    $.mobile.loading("hide");
    const message = e.responseJSON ? e.responseJSON.message : e.statusText;
    showMessage("<h3 color=red>Nie udało się wysłać zgłoszenia!</h3>" + message, 7000);
    $('.ui-btn-right').removeClass("ui-disabled");
    Sentry.captureException(e, {
      extra: message
    });
    if (typeof ga == 'function')
      ga("send", "event", {
        eventCategory: "js-error",
        eventAction: "sendViaAPI"
      });
  });
};

function showMessage(msg, timeout) {
  $.mobile.loading("show", { html: msg, textVisible: true, textonly: true });
  window.setTimeout(function () {
    $.mobile.loading("hide");
  }, timeout);
}

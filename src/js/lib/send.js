/* global ga */

import * as Sentry from "@sentry/browser";

import { updateStatus } from "./status";
import { showMessage, showError } from './showMessage'

import Api from './Api'

window.sendApplication = async function (appId) {
  $(`#${appId} .status-confirmed-waiting`).addClass("ui-disabled");
  $('.ui-btn-right').addClass("ui-disabled");

  $.mobile.loading("show", { text: "Wysyłam...", textVisible: true });

  try {
    const api = new Api(`/api/app/${appId}/send`)
    const msg = await api.patch()
    updateStatus(appId, msg.status);
    $.mobile.loading("hide");
    showMessage("<p>Wysłane</p>")
    if ($(".dziekujemy").length) {
      $(".whatNext").hide();
      $(".afterSend").show();
    }
    $('.ui-btn-right').removeClass("ui-disabled");
    $(`#${appId}`).trigger('collapsibleexpand')
    (typeof ga == 'function') && ga("send", "event", { eventCategory: "js", eventAction: "sendViaAPI" });
  } catch (e) {
    $.mobile.loading("hide");
    $('.ui-btn-right').removeClass("ui-disabled");
    showError(e.message)
    Sentry.captureException(e, {
      extra: e.message
    });
    if (typeof ga == 'function')
      ga("send", "event", {
        eventCategory: "js-error",
        eventAction: "sendViaAPI"
      });

  }
  
};


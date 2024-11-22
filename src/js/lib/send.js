import $ from "jquery"

import * as Sentry from "@sentry/browser";

import { updateStatus } from "./status";
import { showMessage, showError } from './showMessage'

import Api from './Api'

// @ts-ignore
window.sendApplication = async function (appId) {
  const $whatNext = $(".whatNext")
  const $afterSend = $(".afterSend")
  const $buttonRight = $('.ui-btn-right')

  $(`#${appId} .status-confirmed-waiting`).addClass("ui-disabled")
  $buttonRight.addClass("ui-disabled")

  $.mobile.loading("show", { text: "Wysyłam...", textVisible: true })

  try {
    const api = new Api(`/api/app/${appId}/send`)
    const msg = await api.patch()
    updateStatus(appId, msg.status)
    $.mobile.loading("hide")
    showMessage("<p>Wysłane</p>")
    if ($(".dziekujemy").length) {
      $whatNext.hide();
      $afterSend.show();
    }
    if ($('.my-applications').length) {
      $(`#${appId}`).trigger('collapsibleexpand')
    }
    // @ts-ignore
    (typeof ga == 'function') && ga("send", "event", { eventCategory: "js", eventAction: "sendViaAPI" })
  } catch (e) {
    $.mobile.loading("hide")
    showError(e.message)
    $whatNext.hide()
    $afterSend.text('Błąd: ' + e.message).show().addClass('error')
    Sentry.captureException(e, {
      extra: e.message
    })
    // @ts-ignore
    if (typeof ga == 'function')
      // @ts-ignore
      ga("send", "event", {
        eventCategory: "js-error",
        eventAction: "sendViaAPI"
      })

  }
  $buttonRight.removeClass("ui-disabled")
};


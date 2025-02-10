import $ from "jquery"

import * as Sentry from "@sentry/browser";

import { updateStatus } from "./status";
import { toast, error, message } from './toast'

import Api from './Api'
import { appClicked, closeAllApps } from "../sites/my-application";

async function sendApplication(/** @type {string} */ appId) {
  const $whatNext = $(".whatNext")
  const $afterSend = $(".afterSend")

  $(`#${appId} .status-confirmed-waiting`).addClass("disabled")

  message("Wysyłam...")

  try {
    const api = new Api(`/api/app/${appId}/send`)
    const msg = await api.patch()
    if (msg.status == 'redirect')
      return location.href = '/brak-sm.html?id=' + appId

    updateStatus(appId, msg.status)
    toast("Wysłane")
    if ($(".dziekujemy").length) {
      $whatNext.hide();
      $afterSend.show();
    }
    if ($('.my-applications').length) {
      closeAllApps()
      appClicked(document.getElementById(appId))
    }
    // @ts-ignore
    (typeof ga == 'function') && ga("send", "event", { eventCategory: "js", eventAction: "sendViaAPI" })
  } catch (e) {
    error(e.message)
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
  showButtons()
}

export function showButtons() {
  $('.button.disabled').removeClass("disabled")
}

export default sendApplication

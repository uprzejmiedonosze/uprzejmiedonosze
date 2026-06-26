import * as Sentry from "@sentry/browser";

import { updateStatus } from "./status";
import { toast, error, message } from './toast'

import Api from './Api'
import { appClicked, closeAllApps } from "../sites/my-apps";

async function sendApplication(/** @type {string} */ appId) {
  const whatNext = document.querySelector(".whatNext")
  const afterSend = document.querySelector(".afterSend")

  // Use getElementById to avoid issues with IDs starting with numbers
  const appElement = document.getElementById(appId)
  const statusElement = appElement?.querySelector('.status-confirmed-waiting')
  if (statusElement) statusElement.classList.add("disabled")

  message("Wysyłam...")

  try {
    const api = new Api(`/api/app/${appId}/send`)
    const msg = await api.patch()
    updateStatus(appId, msg.status)
    toast("Wysłane")
    if (document.querySelector(".dziekujemy")) {
      if (whatNext) /** @type {HTMLElement} */ (whatNext).style.display = 'none'
      if (afterSend) /** @type {HTMLElement} */ (afterSend).style.display = 'block'
    }
    if (document.querySelector('.my-applications')) {
      closeAllApps()
      appClicked(document.getElementById(appId))
    }
    // @ts-ignore
    (typeof ga == 'function') && ga("send", "event", { eventCategory: "js", eventAction: "sendViaAPI" })
  } catch (e) {
    error(e.message)
    if (whatNext) /** @type {HTMLElement} */ (whatNext).style.display = 'none'
    Sentry.captureException(e, {
      extra: e.message
    })
    if (afterSend) {
      afterSend.textContent = 'Błąd: ' + e.message;
      /** @type {HTMLElement} */ (afterSend).style.display = 'block'
      afterSend.classList.add('error')
    }
    // @ts-ignore
    if (typeof ga == 'function')
      // @ts-ignore
      ga("send", "event", {
        eventCategory: "js-error",
        eventAction: "sendViaAPI"
      })
    throw e // Re-throw to allow caller to handle
  } finally {
    showButtons()
  }
}

export function showButtons() {
  document.querySelectorAll('.button.disabled').forEach(button => {
    button.classList.remove("disabled")
  })
}

export default sendApplication

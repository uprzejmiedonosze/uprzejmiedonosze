import sendApplication, { showButtons } from "../lib/send"
import { error } from "../lib/toast"

document.addEventListener("DOMContentLoaded", () => {
  if (!document.getElementsByClassName('dziekujemy').length)
    return

  const appIdElement = /** @type {HTMLInputElement} */ (document.getElementById('applicationId'))
  const automatedSMElement = /** @type {HTMLInputElement} */ (document.getElementById('automatedSM'))
  const appId = appIdElement?.value
  const automatedSM = automatedSMElement?.value

  if (appId && automatedSM) {
    // Immediate send instead of setTimeout to avoid race conditions
    // where user closes tab or browser suspends execution
    sendApplication(appId).catch(err => {
       error(err)
    })
  } else {
    showButtons()
  }

  _paq.push(['trackEvent', 'Application', 'thank-you', appId]);
});

import sendApplication, { showButtons } from "../lib/send"

document.addEventListener("DOMContentLoaded", () => {
  if (!document.getElementsByClassName('dziekujemy').length)
    return

  const appIdElement = /** @type {HTMLInputElement} */ (document.getElementById('applicationId'))
  const automatedSMElement = /** @type {HTMLInputElement} */ (document.getElementById('automatedSM'))
  const appId = appIdElement?.value
  const automatedSM = automatedSMElement?.value

  if (appId && automatedSM) {
    setTimeout(() => {
      sendApplication(appId)
    }, 1000)
  } else {
    showButtons()
  }

  // @ts-ignore
  (typeof umami == 'object') && umami.track("start", {
    appId
  });
});

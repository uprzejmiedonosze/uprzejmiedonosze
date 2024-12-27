import sendApplication from "../lib/send";

document.addEventListener("DOMContentLoaded", () => {
  if (!document.getElementsByClassName('dziekujemy').length)
    return

  var applicationId = document.getElementById('applicationId')?.value
  var automatedSM = document.getElementById('automatedSM')?.value

  if (applicationId && automatedSM) {
    sendApplication(applicationId)
  }

  // @ts-ignore
  (typeof ga == 'function') && ga("send", "event", { eventCategory: "pageshow", eventAction: "dziekujemy" });

});

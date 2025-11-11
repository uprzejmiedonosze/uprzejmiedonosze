import sendApplication, { showButtons } from "../lib/send"
import $ from "jquery"

document.addEventListener("DOMContentLoaded", () => {
  if (!document.getElementsByClassName('dziekujemy').length)
    return

  var applicationId = document.getElementById('applicationId')?.value
  var automatedSM = document.getElementById('automatedSM')?.value

  if (applicationId && automatedSM) {
    setTimeout(() => {
      sendApplication(applicationId)
    }, 1000)
  } else {
    showButtons()
  }

  //const appId = document.getElementById('applicationId')?.value

  // @ts-ignore
  //(typeof umami == 'object') && umami.track("start", {
//    appId
 // });

});

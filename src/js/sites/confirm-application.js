document.addEventListener("DOMContentLoaded", () => {
  if (!document.querySelector(".confirm-application")) return;

  const appIdElement = document.getElementById("applicationId")
  let appId = null
  if (appIdElement && 'value' in appIdElement) {
    appId = appIdElement.value
  }

  _paq.push(['trackEvent', 'Application', 'set-status', appId]);

  setTimeout(function () {
    const confirmButton = document.querySelector("a.confirm-send-button")
    if (confirmButton) {
      confirmButton.classList.remove('disabled')
    }
  }, 1500);
});


function confirmApplication() {
  const form = /** @type {HTMLFormElement} */ (document.getElementById('form'))
  if (form) {
    form.submit();
  }
  const saveButton = document.querySelector('.confirm-save-button')
  if (saveButton) {
    saveButton.classList.add('disabled')
  }
}

// @ts-ignore
window.confirmApplication = confirmApplication;

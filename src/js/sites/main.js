import $ from "jquery";

$(document).on("pageshow", function () {
  if (!$(".mainPage").length) return;
  // @ts-ignore
  (typeof ga == 'function') && ga("send", "event", { eventCategory: "pageshow", eventAction: "index" });
});


const dialogs = document.querySelectorAll('dialog')

dialogs.forEach(dialog =>
  dialog.addEventListener('mousedown', event => {
    if (event.target === event.currentTarget) {
        event.currentTarget?.close()
    }
  })
)

const popups = document.querySelectorAll('a[data-rel=popup]');
popups.forEach(popup => {
  popup.addEventListener("click", function(e) {
    const id = this.hash.substring(1)
    document.getElementById(id)?.showModal()
  })
})
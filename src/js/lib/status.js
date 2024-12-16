import $ from "jquery"

import Api from './Api'

const statuses = require("../../api/config/statuses.json");

export async function setStatus(appId, status) {
  if (statuses[status]?.confirmationNeeded) {
    const confirmation = window.confirm('Czy na pewno chcesz przeniesć zgłoszenie do archiwum?');
    if (!confirmation) return
  }

  const changeStatusButton = document.querySelector(`#changeStatus${appId}`)
  if (changeStatusButton)
    changeStatusButton.classList.add('disabled')
  
  const api = new Api(`/api/app/${appId}/status/${status}`);
  const result = await api.patch();
  
  if (result?.patronite)
    // @ts-ignore
    document.getElementById('patronite')?.showModal()
  
  updateStatus(appId, status)

  if (changeStatusButton) {
    changeStatusButton.classList.remove('disabled')
  }

  // @ts-ignore
  (typeof ga == 'function') && ga("send", "event", {
    eventCategory: "js",
    eventAction: "setStatus",
    eventLabel: status
  });
}

// @ts-ignore
window.setStatus = setStatus;

export function updateStatus(appId, status) {
  const statusDef = statuses[status]
  const $popup = $("#changeStatus" + appId)
  const $application = $("#" + appId)
  $popup.find("li a").parent().hide()

  statusDef.allowed.forEach(function (allowed) {
    $popup.find("a." + allowed).parent().show()
  });

  const allClasses = Object.keys(statuses).join(" ")
  $application.removeClass(allClasses)
  $application.addClass(status)
  $application.find('div.status-dot').removeClass(allClasses)
  $application.find('div.status-dot').addClass(status)
  $application.find(".application-details-list > .status-dot > b").text(statusDef.name.toUpperCase());
  $application.find(".top-line > .status-dot > b").text(statusDef.name.toUpperCase());

  updateCounters();
}

export function updateCounters() {
  $(".status-filter li").each(function (_idx, item) {
    const count = $("div.application." + item.children[0].id).length;
    // @ts-ignore
    item.children[1].innerText = count;
    if (count == 0) {
      $(item).hide();
    } else {
      $(item).show();
    }
  });

  const $sendMenu = $("li.wysylka a span")
  if ($('.dziekujemy').length) { // send on thank page
    $sendMenu.text(parseInt($sendMenu.text()) - 1)
  } else {
    $sendMenu.text($("div.application.confirmed").length);
  }
  if (parseInt($sendMenu.text()) <= 0) {
    $sendMenu.parent().hide()
  }
}

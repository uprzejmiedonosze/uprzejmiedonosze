/* global ga */

const statuses = require("../../api/config/statuses.json");

export function setStatus(appId, status) {
  $.mobile.loading("show", { textVisible: false });
  $.post("/api/api.html", { action: status, id: appId }).done(function () {
    _updateStatus(appId, status);
    $.mobile.loading("hide")
  });
  ga("send", "event", {
    eventCategory: "js",
    eventAction: "setStatus",
    eventLabel: status
  });
}
window.setStatus = setStatus;

export function _updateStatus(appId, status) {
  $("#" + appId + " .appActionButtons a")
    .removeClass("ui-disabled")
    .hide();
  $("#" + appId + " .appActionButtons a.status-" + status)
    .addClass("ui-disabled")
    .show();
  statuses[status].allowed.forEach(function (allowed) {
    $("#" + appId + " .appActionButtons a.status-" + allowed).show();
  });

  const allClasses = Object.keys(statuses).join(" ");
  $("#" + appId).removeClass(allClasses);
  $("#" + appId).addClass("status-" + status);

  $("#" + appId + " b.currentStatus").text(
    $("#" + appId + " .appActionButtons a.status-" + status).text()
  );

  window.updateCounters();
}
window._updateStatus = _updateStatus;

export function updateCounters() {
  $(".filter a").each(function (_idx, item) {
    const count = $("div.application.status-" + item.id).length;
    item.children[0].innerText = count;
    if (count == 0) {
      $(item).hide();
    } else {
      $(item).show();
    }
  });

  $("li.wysylka a span").text($("div.application.status-confirmed").length);
}
window.updateCounters = updateCounters;

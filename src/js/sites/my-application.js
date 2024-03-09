import { updateCounters } from "../lib/status";

$(document).on("pageshow", function () {
  if (!$(".my-applications").length) return;

  $("div.displayAllApps a").click(function () {
    $("div.displayAllApps").hide();
    $("div.application:not(.status-archived)").show();
  });

  updateCounters();
  $(".filter a").click(function (_e) {
    $("div.application").hide();
    $("div.application.status-" + this.id).show();
    $(".filter a").each(function (_idx, item) {
      $(item).removeClass("active");
    });
    $(this).addClass("active");
  });
  window._recydywa = function(plateId) {
    $input = $('#autocomplete-input')
    $input.val(plateId)
  }
});

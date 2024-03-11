import { updateCounters } from "../lib/status";

$(document).on("pageshow", function () {
  if (!$(".my-applications").length) return;

  $("div.displayAllApps a").click(function () {
    $("div.displayAllApps").hide();
    $("div.application:not(.archived)").show();
  });

  updateCounters();

  $(".status-filter a").click(function (_e) {
    // unclick scenarion
    if (this.classList.contains('active')) {
      $(this).removeClass("active");
      $("div.application:not(.archived)").show();
      return;
    }
    $("div.application").hide();
    $("div.application." + this.id).show();
    $(".status-filter a").removeClass("active");
    $(this).addClass("active");
  });

  window._recydywa = function(plateId) {
    $input = $('#autocomplete-input')
    $input.val(plateId)
    $input.keyup()
    $("[data-role=collapsible]").collapsible( "collapse" ) // close all
  }
});

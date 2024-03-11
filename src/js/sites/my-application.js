import { updateCounters } from "../lib/status";

$(document).on("pageshow", function () {

  displayAllApps = function () {
    $("div.displayAllApps").hide();
    $("div.application:not(.archived)").show();
  }

  if (!$(".my-applications").length) return;

  $("div.displayAllApps a").click(displayAllApps);

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

  window._recydywa = function (plateId) {
    $input = $('#autocomplete-input')
    $input.val(plateId)
    $input.keyup()
    $("[data-role=collapsible]").collapsible("collapse") // close all
    displayAllApps()
  }

  $("#collapsiblesetForFilter").on("collapsibleexpand", (e) => {
    const target = e.target
    const appId = target.id
    const appDetailsDiv = $(target).find('.ui-collapsible-content div')

    appDetailsDiv.html('<div class="loader"></div>')

    $.ajax({
      url: `/short-${appId}-partial.html`,
      dataType: "html"
    }).then(function (appDetails) {
      location.hash = appId
      appDetailsDiv.html(appDetails)
      $(`#changeStatus${appId}`).popup()
    });
  })




});

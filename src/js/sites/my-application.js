import { updateCounters } from "../lib/status";
import showMessage from './../lib/showMessage'

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

    const resizeTextarea = function(){
      $(this).height(0).height(this.scrollHeight);
    };

    $.ajax({
      url: `/short-${appId}-partial.html`,
      dataType: "html"
    }).then(function (appDetails) {
      location.hash = `#${appId}`
      appDetailsDiv.html(appDetails)
      $(`#changeStatus${appId}`).popup()
      const $privateComment = $('.private-comment > textarea');

      $('.app-field-editable')
        .on('focusout', function() {
          if (this.dataset.initialValue === this.value) {
            return;
          }
          $target = $(this)

          const body = {
            [this.name]: this.value
          };
          $.ajax({
            url: `/api/app/${ appId }/fields`,
            dataType: "json",
            method: 'PATCH',
            data: JSON.stringify(body),
            contentType: 'application/json',
            beforeSend: (e) => {
              $target.attr('readonly', true)
            },              
            success: (e) => {
              $target.removeAttr('readonly')
              $target.removeClass("error")
              this.setAttribute("data-initial-value", this.value)
            }
          }).fail(e => {
            $target.removeAttr('readonly')
            $target.addClass("error")
            const message = e.responseJSON ? e.responseJSON.error : e.statusText
            showMessage(message, 7000)
          })
        })
      $privateComment
        .on('keyup', resizeTextarea)
        .trigger('keyup');
    });
  })
});

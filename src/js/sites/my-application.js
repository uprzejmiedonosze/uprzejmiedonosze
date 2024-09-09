import { updateCounters } from "../lib/status";
import { setStatus } from "../lib/status"
import Api from '../lib/Api'

$(document).on("pageshow", function () {
  const displayAllApps = function () {
    $("div.displayAllApps").hide();
    $("div.application:not(.archived)").show();
  }

  if (!$(".my-applications").length) return;

  $("#collapsiblesetForFilter" ).on("filterablebeforefilter", function() {
    $.mobile.loading("show")
    $('b.recydywaInfo').css('display', 'none');

  })

  $("#collapsiblesetForFilter" ).on("filterablefilter", function() {
    $.mobile.loading("hide")
    const should = $("div.application:not(.ui-screen-hidden):first .recydywa.small").data('recydywacnt')
    const is = $("div.application:not(.ui-screen-hidden)").length
    if (is < should)
      $('b.recydywaInfo').css('display', 'block');
  });


  $("div.displayAllApps a").on('click', displayAllApps);

  updateCounters();

  $(".status-filter a").on('click', function (_e) {
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

  $('#recydywa').on('click', function() {
    $(this).hide();
  })


  $("#collapsiblesetForFilter").on("collapsibleexpand", async (e) => {
    const target = e.target
    const appId = target.id
    const appDetailsDiv = $(target).find('.ui-collapsible-content div')

    appDetailsDiv.html('<div class="loader"></div>')

    const resizeTextarea = function(){
      $(this).height(0).height(this.scrollHeight);
    };

    const api = new Api(`/short-${appId}-partial.html`)
    const appDetails = await api.getHtml()
    location.hash = `#${appId}`
    appDetailsDiv.html(appDetails)
    $(`#changeStatus${appId}`).popup()
    const $privateComment = $('.private-comment > textarea')
    $privateComment.on('keyup', resizeTextarea).trigger('keyup')

    $('a.recydywa').on('click', async function () {
      const plateId = $(this).data('plateid')
      const $popup = $('#recydywa')
      const $popupContent = $('#recydywa .popup-content')
      $popupContent.html('<div class="loader"></div>')
      $popup.show()
      const api = new Api(`/recydywa-${plateId}-partial.html`)
      const recydywa = await api.getHtml()
      $popupContent.html(recydywa)
    })
  

    $('.app-field-editable')
      .on('focusout', async function() {
        if (this.dataset.initialValue === this.value) {
          return;
        }
        var $target = $(this)

        const body = {
          [this.name]: this.value
        };

        $target.attr('readonly', true)
        try {
          const api = new Api(`/api/app/${ appId }/fields`)
          const reply = await api.patch(body)
          $target.removeAttr('readonly')
          $target.removeClass("error")
          this.setAttribute("data-initial-value", this.value)

          if (reply.suggestStatusChange) {
            if (confirm('Zgłoszenie ma numer sprawy. Zmienić jego status na „potwierdzone”?')) {
              setStatus(appId, 'confirmed-sm')
            }
          }
        } catch(e) {
          $target.removeAttr('readonly')
          $target.addClass("error")
        }
      })
  })
});

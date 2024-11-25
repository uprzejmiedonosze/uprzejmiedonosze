import $ from "jquery"

import { updateCounters } from "../lib/status";
import { setStatus } from "../lib/status"
import Api from '../lib/Api'
import filterable from "../lib/filterable";

document.addEventListener("DOMContentLoaded", (event) => {
  const appsList = document.getElementById('apps-list')
  if (!appsList) return

  filterable('apps')

  const appHeaders = appsList?.getElementsByTagName('h3') || []
  for (let h3 of appHeaders)
    h3.addEventListener("click", appClickEvent)
})


function closeAllApps() {
  const expanded = document.querySelectorAll("#apps-list .expanded")
  for (let app of expanded) {
    app.classList.remove("expanded")
    const appDetailsDiv = app.getElementsByClassName("app-details").item(0)
    // @ts-ignore
    appDetailsDiv.innerHTML = ""
  }
}


async function appClickEvent() {
  const target = this.parentElement
  const appId = target.id
  const appDetailsDiv = target.getElementsByClassName("app-details").item(0)

  const close = target.classList.contains("expanded")
  closeAllApps()
  if (close) return // dont expand clicked element

  target.classList.add("expanded")

  appDetailsDiv.innerHTML = '<div class="loader"></div>'

  const api = new Api(`/short-${appId}-partial.html`)
  const appDetails = await api.getHtml()
  location.hash = `#${appId}`
  appDetailsDiv.innerHTML = appDetails
  $(`#changeStatus${appId}`).popup()
  $('.private-comment textarea').on('keyup',  function(){
    $(this).height(0).height(this.scrollHeight);
  }).trigger('keyup')

  $('a.recydywa-seemore').on('click', async function () {
    const plateId = $(this).data('plateid')
    const $recydywa = $('#recydywa')
    const $recydywaContent = $('#recydywa .popup-content')
    $recydywaContent.html('<div class="loader"></div>')
    $recydywa.show()
    const api = new Api(`/recydywa-${plateId}-partial.html`)
    const recydywa = await api.getHtml()
    $recydywaContent.html(recydywa)
  })

  $('.app-field-editable')
    .on('focusout', async function() {
      const initialValue = this.dataset.initialValue ?? ''
      const newValue = this.value ?? ''
      if (initialValue === newValue) {
        return;
      }
      var $target = $(this)

      const body = {
        [this.name]: newValue
      };

      $target.attr('readonly', "true")
      try {
        const api = new Api(`/api/app/${appId}/fields`)
        const reply = await api.patch(body)
        $target.removeAttr('readonly')
        $target.removeClass("error")
        this.setAttribute("data-initial-value", newValue)

        if (reply.suggestStatusChange) {
          if (confirm('Zgłoszenie ma numer sprawy. Zmienić jego status na „potwierdzone”?')) {
            setStatus(appId, 'confirmed-sm')
          }
        }

        const app = $(`#${appId}`)
        const oldFilterText = app.attr('data-filtertext') ?? ''
        if (initialValue.length>5) app.attr('data-filtertext', oldFilterText.replace(initialValue, newValue))
        else app.attr('data-filtertext', oldFilterText + newValue)
      } catch(e) {
        $target.removeAttr('readonly')
        $target.addClass("error")
      }
    })
}

$(document).on("pageshow", function () {
  const displayAllApps = function () {
    $("div.displayAllApps").hide();
    $("div.application:not(.archived)").show();
  }

  if (!$(".my-applications").length) return;

  $("#collapsiblesetForFilter" ).on("filterablebeforefilter", function() {
    $.mobile.loading("show")
  })

  $("#collapsiblesetForFilter" ).on("filterablefilter", function() {
    $.mobile.loading("hide")
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


  const $recydywa = $('#recydywa')

  // close „recydywa” dialong on Esc
  $(document).on('keyup', e => e.key === "Escape" && $recydywa.hide())
  $recydywa.on('click', _e => $recydywa.hide())

  if ($('#autocomplete-input').val() !== '') {
    $('#autocomplete-input').trigger("keyup");
  }
});

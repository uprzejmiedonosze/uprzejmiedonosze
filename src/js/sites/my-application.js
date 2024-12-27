import $ from "jquery"

import { updateCounters } from "../lib/status";
import { setStatus } from "../lib/status"
import Api from '../lib/Api'
import { filterable, triggerFilter} from "../lib/filterable";
import makeDropdown from "../lib/dropdown";
import makeDialog from "../lib/dialog";
import sendApplication from "../lib/send";

document.addEventListener("DOMContentLoaded", (event) => {
  const appsList = document.getElementById('apps-list')
  if (!appsList) return

  filterable('apps', 'apps-list')
  makeDialog()

  // app headers clickable
  const appHeaders = appsList?.getElementsByTagName('h3') || []
  for (let h3 of appHeaders)
    h3.addEventListener("click", appClickHandler)

  // filters
  const filters = document.querySelectorAll(".status-filter a") || []
  for (let filter of filters) {
      filter.addEventListener("click", filterAppsHandler)
  }

  // show all apps
  const displayAllAppsBtn = document.querySelector("div.displayAllApps a")
  displayAllAppsBtn?.addEventListener("click", displayAllAppsHandler)

  updateCounters()

  const $recydywa = $('#recydywa')

  // close „recydywa” dialong on Esc
  $(document).on('keyup', e => e.key === "Escape" && $recydywa.hide())
  $recydywa.on('click', _e => $recydywa.hide())
})

function filterAppsHandler() {
    if (this.classList.contains('active')) {
      this.classList.remove("active")
      $("div.application:not(.archived)").show()
      return
    }
    $("div.application").hide();
    $("div.application." + this.id).show();
    $(".status-filter a").removeClass("active");
    this.classList.add("active")
}


function displayAllAppsHandler() {
  $("div.displayAllApps").hide();
  $("div.application:not(.archived)").show();
  triggerFilter('apps')
}

export function closeAllApps() {
  const expanded = document.querySelectorAll("#apps-list .expanded")
  for (let app of expanded) {
    app.classList.remove("expanded")
    const appDetailsDiv = app.getElementsByClassName("app-details").item(0)
    // @ts-ignore
    appDetailsDiv.innerHTML = ""
  }
}

async function appClickHandler() {
  appClicked(this.parentElement)
}

/**
 * @param {HTMLElement|null} target
 */
export async function appClicked(target) {
  if (!target) return

  const appId = target.id
  const appDetailsDiv = target.getElementsByClassName("app-details").item(0)

  const close = target.classList.contains("expanded")
  closeAllApps()
  if (close) return // dont expand clicked element

  target.classList.add("expanded")

  // @ts-ignore
  appDetailsDiv.innerHTML = '<div class="loader"></div>'

  const api = new Api(`/short-${appId}-partial.html`)
  const appDetails = await api.getHtml()
  location.hash = `#${appId}`
  // @ts-ignore
  appDetailsDiv.innerHTML = appDetails

  makeDropdown()

  $('a.send-application').on('click', async function () {
    const appId = $(this).data('appid')
    sendApplication(appId)
  })

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

import { setStatus } from "../lib/status"
import Api from '../lib/Api'
import delay from "../lib/delay";
import makeDropdown from "../lib/dropdown";
import makeDialog from "../lib/dialog";
import sendApplication from "../lib/send";
import { error } from "../lib/toast"

const PAGE_SIZE = 100

document.addEventListener("DOMContentLoaded", () => {
  const appsList = document.getElementById('apps-list')
  if (!appsList) return

  makeDialog()

  // the list is replaced/appended on search, so delegate clicks
  appsList.addEventListener("click", e => {
    const target = /** @type {HTMLElement} */ (e.target)
    const more = target.closest("div.displayAllApps a")
    if (more) {
      e.preventDefault()
      loadMore(/** @type {HTMLElement} */ (more))
      return
    }
    const h3 = target.closest("h3")
    if (h3 && h3.parentElement?.parentElement === appsList)
      appClicked(h3.parentElement)
  })

  const search = /** @type {HTMLInputElement|null} */ (document.getElementById('apps'))
  search?.addEventListener("input", delay(() => reloadApps(), 300))

  const filters = document.querySelectorAll(".status-filter a") || []
  for (let filter of filters)
    filter.addEventListener("click", filterAppsHandler)

  // close „recydywa” dialong on Esc
  const recydywaElement = document.getElementById('recydywa')
  if (recydywaElement) {
    document.addEventListener('keyup', e => {
      if (e.key === "Escape") {
        recydywaElement.style.display = 'none'
      }
    })
    recydywaElement.addEventListener('click', _e => {
      recydywaElement.style.display = 'none'
    })
  }
})

/** @param {Event} e */
function filterAppsHandler(e) {
  e.preventDefault()
  const appsList = document.getElementById('apps-list')
  if (!appsList) return
  const target = /** @type {HTMLElement} */ (this)
  const active = target.classList.contains('active')
  document.querySelectorAll(".status-filter a").forEach(el => el.classList.remove("active"))
  if (!active) target.classList.add("active")
  appsList.dataset.status = active ? 'all' : target.id
  reloadApps()
}

let lastRequest = 0

/**
 * @param {number} offset
 */
async function fetchApps(offset) {
  const appsList = document.getElementById('apps-list')
  const search = /** @type {HTMLInputElement|null} */ (document.getElementById('apps'))
  const params = new URLSearchParams({
    status: appsList?.dataset.status || 'all',
    search: search?.value.trim() || '',
    limit: String(PAGE_SIZE),
    offset: String(offset)
  })
  const request = ++lastRequest
  const html = await new Api(`/app/partial?${params}`).getHtml()
  // a newer search was started in the meantime
  if (request !== lastRequest) return null

  // keep the filter on reload
  const url = new URL(window.location.href)
  url.search = ''
  if (params.get('status') !== 'all') url.searchParams.set('status', params.get('status') || '')
  if (params.get('search')) url.searchParams.set('q', params.get('search') || '')
  history.replaceState(null, '', url)
  return html
}

async function reloadApps() {
  const appsList = document.getElementById('apps-list')
  if (!appsList) return
  appsList.classList.add('loading')
  try {
    const html = await fetchApps(0)
    if (html !== null) appsList.innerHTML = html
  } catch (e) {
    error(e.message)
  } finally {
    appsList.classList.remove('loading')
  }
}

/** @param {HTMLElement} button */
async function loadMore(button) {
  const container = button.parentElement
  button.classList.add('disabled')
  try {
    const html = await fetchApps(parseInt(button.dataset.offset || '0'))
    if (html === null) return
    container?.insertAdjacentHTML('beforebegin', html)
    container?.remove()
  } catch (e) {
    button.classList.remove('disabled')
    error(e.message)
  }
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

  const api = new Api(`/app/partial/${appId}`)
  const appDetails = await api.getHtml()
  location.hash = `#${appId}`
  // @ts-ignore
  appDetailsDiv.innerHTML = appDetails

  makeDropdown()

  document.querySelectorAll('a.send-application').forEach(link => {
    link.addEventListener('click', async function (e) {
      e.preventDefault()
      
      // Prevent multiple clicks
      if (this.classList.contains('sending')) {
        return
      }
      
      this.classList.add('sending')
      const appId = this.dataset.appid
      
      try {
        await sendApplication(appId)
      } catch (err) {
        console.error('Failed to send application:', err)
        // Error toast is already shown by sendApplication, just log here
      } finally {
        this.classList.remove('sending')
      }
    })
  })

  document.querySelectorAll('a.recydywa-seemore').forEach(link => {
    link.addEventListener('click', async function () {
      const plateId = this.dataset.plateid
      const recydywaElement = document.getElementById('recydywa')
      const recydywaContent = document.querySelector('#recydywa .popup-content')
      if (recydywaContent) recydywaContent.innerHTML = '<div class="loader"></div>'
      if (recydywaElement) recydywaElement.style.display = 'block'
      const api = new Api(`/app/recydywa/partial/${plateId}`)
      const recydywa = await api.getHtml()
      if (recydywaContent) recydywaContent.innerHTML = recydywa
    })
  })

  document.querySelectorAll('.app-field-editable').forEach(field => {
    field.addEventListener('focusout', async function() {
      const initialValue = this.dataset.initialValue ?? ''
      const newValue = this.value ?? ''
      if (initialValue === newValue) {
        return;
      }


      const body = {
        [this.name]: newValue
      };

      this.setAttribute('readonly', "true")
      try {
        const api = new Api(`/api/app/${appId}/fields`)
        const reply = await api.patch(body)
        this.removeAttribute('readonly')
        this.classList.remove("error")
        this.setAttribute("data-initial-value", newValue)

        if (reply.suggestStatusChange) {
          if (confirm('Zgłoszenie ma numer sprawy. Zmienić jego status na „potwierdzone”?')) {
            setStatus(appId, 'confirmed-sm')
          }
        }

      } catch(e) {
        this.removeAttribute('readonly')
        this.classList.add("error")
      }
    })
  })
}

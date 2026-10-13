import Api from './Api'

const statuses = require("../../api/config/statuses.json");

export async function setStatus(appId, status) {
  if (statuses[status]?.confirmationNeeded) {
    const confirmation = window.confirm('Czy na pewno chcesz przeniesć zgłoszenie do archiwum?');
    if (!confirmation) return
  }

  const changeStatusButton = document.getElementById(`changeStatus${appId}`)
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

  _paq.push(['trackEvent', 'Application', 'set-status', status]);

}

// @ts-ignore
window.setStatus = setStatus;

export function updateStatus(appId, status) {
  const statusDef = statuses[status]
  const popup = document.getElementById("changeStatus" + appId)
  const application = document.getElementById(appId)
  
  if (popup) {
    const links = popup.querySelectorAll("li a")
    links.forEach(link => {
      const parent = /** @type {HTMLElement} */ (link.parentElement)
      if (parent) parent.style.display = 'none'
    })

    statusDef.allowed.forEach(function (allowed) {
      const allowedLink = popup.querySelector("a." + allowed)
      if (allowedLink && allowedLink.parentElement) {
        /** @type {HTMLElement} */ (allowedLink.parentElement).style.display = 'block'
      }
    });
  }

  const oldStatus = Object.keys(statuses).find(s => application?.classList.contains(s))

  if (application) {
    const allClasses = Object.keys(statuses).join(" ")
    application.classList.remove(...allClasses.split(' '))
    application.classList.add(status)
    
    const statusDots = application.querySelectorAll('div.status-dot')
    statusDots.forEach(dot => {
      dot.classList.remove(...allClasses.split(' '))
      dot.classList.add(status)
    })
    
    const detailsStatusText = application.querySelector(".application-details-list > .status-dot > b")
    if (detailsStatusText) {
      detailsStatusText.textContent = statusDef.name.toUpperCase()
    }
    
    const topLineStatusText = application.querySelector(".top-line > .status-dot > b")
    if (topLineStatusText) {
      topLineStatusText.textContent = statusDef.name.toUpperCase()
    }
  }

  updateCounters(oldStatus, status);
}

/**
 * Status filter counters on /app/list come from server-side stats (the list
 * itself is paged), so a status change only moves one report between them.
 * @param {string|undefined} oldStatus
 * @param {string} newStatus
 */
export function updateCounters(oldStatus, newStatus) {
  if (!oldStatus || oldStatus === newStatus) return
  bumpCounter(oldStatus, -1)
  bumpCounter(newStatus, 1)
}

function bumpCounter(status, delta) {
  const link = document.querySelector(`.status-filter a[id="${status}"]`)
  const item = /** @type {HTMLElement|null} */ (link?.parentElement)
  const counter = item?.querySelector('.li-count')
  if (!item || !counter) return
  const count = Math.max(0, parseInt(counter.textContent || '0') + delta)
  counter.textContent = count.toString()
  item.style.display = count > 0 ? 'block' : 'none'
}

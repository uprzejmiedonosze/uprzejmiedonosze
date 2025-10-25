import Api from './Api'

const statuses = require("../../api/config/statuses.json");

export async function setStatus(appId, status) {
  if (statuses[status]?.confirmationNeeded) {
    const confirmation = window.confirm('Czy na pewno chcesz przeniesć zgłoszenie do archiwum?');
    if (!confirmation) return
  }

  const changeStatusButton = document.querySelector(`#changeStatus${appId}`)
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

  // @ts-ignore
  (typeof umami == 'object') && umami.track("set-status", {
    appId, status
  });

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

  updateCounters();
}

export function updateCounters() {
  const statusFilterItems = document.querySelectorAll(".status-filter li")
  statusFilterItems.forEach((item) => {
    const firstChild = item.children[0]
    if (firstChild && firstChild.id) {
      const count = document.querySelectorAll("div.application." + firstChild.id).length;
      const secondChild = item.children[1]
      if (secondChild) {
        secondChild.textContent = count.toString();
      }
      if (count == 0) {
        /** @type {HTMLElement} */ (item).style.display = 'none';
      } else {
        /** @type {HTMLElement} */ (item).style.display = 'block';
      }
    }
  });

  const sendMenu = document.querySelector("li.wysylka a span")
  if (sendMenu) {
    if (document.querySelector('.dziekujemy')) { // send on thank page
      const currentCount = parseInt(sendMenu.textContent || '0')
      sendMenu.textContent = (currentCount - 1).toString()
    } else {
      const confirmedApps = document.querySelectorAll("div.application.confirmed")
      sendMenu.textContent = confirmedApps.length.toString();
    }
    if (parseInt(sendMenu.textContent || '0') <= 0) {
      const parent = /** @type {HTMLElement} */ (sendMenu.parentElement)
      if (parent) parent.style.display = 'none'
    }
  }
}

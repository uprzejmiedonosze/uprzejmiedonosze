import Api from '../lib/Api'
import { num } from '../lib/format'

const MIN_PLATE_LENGTH = 4
const PLATE_DEBOUNCE_MS = 800

let currentPlateRequestId = 0
let lastCheckedPlate = null
let debounceTimer = null

function normalizePlate(plate) {
    return (plate || "").toString().toUpperCase().replace(/\s+/g, "")
}

export function clearRecydywa() {
    const overlay = document.querySelector(".recydywa-overlay")
    const details = document.querySelector(".recydywa-details")

    if (overlay) {
        /** @type {HTMLElement} */ (overlay).style.display = 'none'
        overlay.classList.remove('onlyme')
    }
    if (details) {
        details.classList.remove('onlyme')
        const detailsElement = /** @type {HTMLElement} */ (details)
        detailsElement.style.visibility = 'hidden'

        const lastTicket = details.querySelector('.recydywa-lastTicket')
        const header = details.querySelector('.recydywa-header')
        const galleryLink = details.querySelector('.recydywa-galleryLink')

        if (lastTicket) lastTicket.innerHTML = ''
        if (header) header.textContent = ''
        if (galleryLink) galleryLink.innerHTML = ''
    }
}

export async function updateRecydywa(appId = null, plateId = null) {
    if (!appId) {
        const appIdElement = /** @type {HTMLInputElement|null} */ (document.querySelector(".new-application #applicationId"))
        appId = appIdElement?.value?.toString()
    }
    if (!appId) return

    const overlay = document.querySelector(".recydywa-overlay")
    const details = document.querySelector(".recydywa-details")

    const plateIdInput = /** @type {HTMLInputElement|null} */ (document.getElementById("plateId"))
    const targetPlate = plateId !== null && plateId !== undefined
        ? plateId.toString().trim()
        : (plateIdInput?.value?.trim() || "")

    const normalizedTarget = normalizePlate(targetPlate)

    if (!normalizedTarget || normalizedTarget.length < MIN_PLATE_LENGTH) {
        lastCheckedPlate = ""
        clearRecydywa()
        return
    }

    lastCheckedPlate = normalizedTarget
    const requestId = ++currentPlateRequestId

    const recydywa = await getRecydywa(appId, targetPlate)

    if (requestId !== currentPlateRequestId) {
        return
    }

    const currentInputVal = normalizePlate(plateIdInput?.value)
    if (currentInputVal !== normalizedTarget) {
        return
    }

    const appsCnt = recydywa?.apps?.length || 0
    if (appsCnt === 0) {
        clearRecydywa()
        return
    }

    const onlyme = (recydywa.apps.filter(item => !item.owner).length || 0) === 0
    const uniqueUsers = new Set(recydywa.apps.map(item => item.email)).size

    if (overlay) {
        /** @type {HTMLElement} */ (overlay).style.display = 'block'
    }
    if (details) {
        /** @type {HTMLElement} */ (details).style.visibility = "visible"
        const lastTicketElement = details.querySelector('.recydywa-lastTicket')
        if (lastTicketElement) lastTicketElement.innerHTML = recydywa.lastTicket || ''
    }

    if (onlyme) {
        if (overlay) overlay.classList.add('onlyme')
        if (details) details.classList.add('onlyme')

        if (overlay) {
            const appsCntElement = overlay.querySelector('.recydywa-appscnt')
            if (appsCntElement) {
                appsCntElement.textContent = 'Zgłosiłeś dotąd ' + num(appsCnt, ['razy', 'raz', 'razy'])
            }
        }

        if (details) {
            const headerElement = details.querySelector('.recydywa-header')
            if (headerElement) {
                headerElement.textContent = 'Pojazd zgłoszony '
                    + num(appsCnt, ['razy', 'raz', 'razy'])
                    + ', wyłącznie przez Ciebie'
            }
        }
    } else {
        if (overlay) overlay.classList.remove('onlyme')
        if (details) details.classList.remove('onlyme')

        if (overlay) {
            const usersCntElement = overlay.querySelector('.recydywa-userscnt')
            const appsCntElement = overlay.querySelector('.recydywa-appscnt')

            if (usersCntElement) {
                usersCntElement.textContent = num(uniqueUsers, ['osób zgłosiło', 'osoba zgłosiła', 'osoby zgłosiły'])
            }
            if (appsCntElement) {
                appsCntElement.textContent = num(appsCnt, ['wykroczeń', 'wykroczenie', 'wykroczenia'])
            }
        }

        if (details) {
            const headerElement = details.querySelector('.recydywa-header')
            if (headerElement) {
                headerElement.textContent = 'Pojazd zgłoszony '
                    + num(appsCnt, ['razy', 'raz', 'razy'])
                    + ', przez '
                    + num(uniqueUsers, ['użytkowników', 'innego użytkownika', 'użytkowników'])
            }
        }
    }

    if (details) {
        const galleryLinkElement = details.querySelector('.recydywa-galleryLink')
        if (galleryLinkElement) {
            if (recydywa.isPresentInGallery) {
                galleryLinkElement.innerHTML = `<a target="_blank" href="https://galeria.uprzejmiedonosze.net/tagged/${recydywa.plateId}">zobacz galerię</a>`
            } else {
                galleryLinkElement.innerHTML = ''
            }
        }
    }
}

export function initRecydywa() {
    const plateIdInput = /** @type {HTMLInputElement|null} */ (document.getElementById("plateId"))
    if (!plateIdInput) return

    const scheduleUpdate = () => {
        if (debounceTimer) clearTimeout(debounceTimer)
        const currentNormalized = normalizePlate(plateIdInput.value)

        if (currentNormalized !== lastCheckedPlate) {
            clearRecydywa()
            const plateImage = document.getElementById("plateImage")
            if (plateImage) plateImage.style.display = "none"
        }

        if (!currentNormalized || currentNormalized.length < MIN_PLATE_LENGTH) {
            lastCheckedPlate = ""
            return
        }

        debounceTimer = setTimeout(() => {
            updateRecydywa()
        }, PLATE_DEBOUNCE_MS)
    }

    plateIdInput.addEventListener("input", scheduleUpdate)
    plateIdInput.addEventListener("change", () => {
        if (debounceTimer) clearTimeout(debounceTimer)
        const currentNormalized = normalizePlate(plateIdInput.value)
        if (currentNormalized !== lastCheckedPlate) {
            clearRecydywa()
            const plateImage = document.getElementById("plateImage")
            if (plateImage) plateImage.style.display = "none"
        }
        if (currentNormalized.length >= MIN_PLATE_LENGTH) {
            updateRecydywa()
        }
    })
}

async function getRecydywa(appId, plateId) {
    const url = plateId
        ? `/api/app/${appId}/recydywa?plateId=${encodeURIComponent(plateId)}`
        : `/api/app/${appId}/recydywa`
    const api = new Api(url)
    try {
        return await api.getJson()
    } catch (err) {
        console.error('Error fetching data:', err)
        return null
    }
}

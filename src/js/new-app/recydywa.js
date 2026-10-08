import Api from '../lib/Api'
import { num } from '../lib/format'

const MIN_PLATE_LENGTH = 4
const PLATE_DEBOUNCE_MS = 800

let currentPlateRequestId = 0
let lastCheckedPlate = null
let debounceTimer = null
let lastRecydywaData = null

function normalizePlate(plate) {
    return (plate || "").toString().toUpperCase().replace(/\s+/g, "")
}

export function updateSameDayNotice() {
    const sameDayElement = /** @type {HTMLElement|null} */ (document.querySelector('.recydywa-sameDay'))
    const lastTicketElement = /** @type {HTMLElement|null} */ (document.querySelector('.recydywa-lastTicket'))
    if (!sameDayElement) return

    if (!lastRecydywaData || !lastRecydywaData.apps || lastRecydywaData.apps.length === 0) {
        sameDayElement.style.display = 'none'
        sameDayElement.textContent = ''
        if (lastTicketElement) lastTicketElement.style.display = 'block'
        return
    }

    const datetimeInput = /** @type {HTMLInputElement|null} */ (document.getElementById('datetime'))
    const currentVal = datetimeInput?.value || ''
    const currentDate = currentVal.slice(0, 10)

    if (!currentDate) {
        sameDayElement.style.display = 'none'
        sameDayElement.textContent = ''
        if (lastTicketElement) lastTicketElement.style.display = 'block'
        return
    }

    const matchingApps = lastRecydywaData.apps.filter(app => app.date && app.date.slice(0, 10) === currentDate)
    if (matchingApps.length === 0) {
        sameDayElement.style.display = 'none'
        sameDayElement.textContent = ''
        if (lastTicketElement) lastTicketElement.style.display = 'block'
        return
    }

    const parts = matchingApps.map(app => {
        const ext = app.externalId ? ` (${app.externalId})` : ''
        const who = app.owner ? 'twoje zgłoszenie' : 'zgłoszenie innej osoby'
        const timeMatch = app.date?.match(/[T ](\d{2}:\d{2})/)
        const timeStr = timeMatch ? ` o godzinie ${timeMatch[1]}` : ''
        const smStr = app.smName ? ` wysłane do ${app.smName}` : ''
        return `${who} nr ${app.number}${ext}${timeStr}${smStr}`
    })

    sameDayElement.style.display = 'block'
    sameDayElement.textContent = `⚠️ Ten pojazd został już zgłoszony tego samego dnia: ${parts.join(', ')}.`

    if (lastTicketElement) {
        const isSingleSameDay = lastRecydywaData.apps.length === 1 && matchingApps.length === 1
        lastTicketElement.style.display = isSingleSameDay ? 'none' : 'block'
    }
}

export function clearRecydywa() {
    lastRecydywaData = null
    const overlay = /** @type {HTMLElement|null} */ (document.querySelector(".recydywa-overlay"))
    const details = /** @type {HTMLElement|null} */ (document.querySelector(".recydywa-details"))

    if (overlay) {
        overlay.style.display = 'none'
        overlay.classList.remove('onlyme')
    }
    if (details) {
        details.classList.remove('onlyme')
        details.style.visibility = 'hidden'

        const lastTicket = /** @type {HTMLElement|null} */ (details.querySelector('.recydywa-lastTicket'))
        const header = details.querySelector('.recydywa-header')
        const sameDay = /** @type {HTMLElement|null} */ (details.querySelector('.recydywa-sameDay'))
        const seemore = /** @type {HTMLElement|null} */ (details.querySelector('.recydywa-seemore'))

        if (lastTicket) {
            lastTicket.innerHTML = ''
            lastTicket.style.display = 'block'
        }
        if (header) header.textContent = ''
        if (sameDay) {
            sameDay.textContent = ''
            sameDay.style.display = 'none'
        }
        if (seemore) {
            seemore.style.display = 'none'
            seemore.removeAttribute('data-plateid')
        }
    }

    const popup = document.getElementById('recydywa')
    if (popup) {
        popup.style.display = 'none'
    }
}

export async function updateRecydywa(appId = null, plateId = null) {
    if (!appId) {
        const appIdElement = /** @type {HTMLInputElement|null} */ (document.getElementById("applicationId"))
        appId = appIdElement?.value?.toString()
    }
    if (!appId) return

    const overlay = /** @type {HTMLElement|null} */ (document.querySelector(".recydywa-overlay"))
    const details = /** @type {HTMLElement|null} */ (document.querySelector(".recydywa-details"))

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

    lastRecydywaData = recydywa

    const onlyme = (recydywa.apps.filter(item => !item.owner).length || 0) === 0
    const uniqueUsers = new Set(recydywa.apps.map(item => item.email)).size

    if (overlay) {
        overlay.style.display = 'block'
    }
    if (details) {
        details.style.visibility = "visible"
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

    updateSameDayNotice()

    if (details) {
        const seemoreButton = /** @type {HTMLElement|null} */ (details.querySelector('.recydywa-seemore'))
        if (seemoreButton) {
            if (uniqueUsers > 1) {
                seemoreButton.setAttribute('data-plateid', recydywa.plateId)
                seemoreButton.style.display = 'inline-block'
            } else {
                seemoreButton.style.display = 'none'
                seemoreButton.removeAttribute('data-plateid')
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

    const datetimeInput = document.getElementById('datetime')
    datetimeInput?.addEventListener('input', updateSameDayNotice)
    datetimeInput?.addEventListener('change', updateSameDayNotice)

    document.querySelector('.recydywa-details')?.addEventListener('click', async e => {
        const target = /** @type {HTMLElement} */ (e.target)
        const seemoreLink = target.closest('a.recydywa-seemore')
        if (seemoreLink) {
            e.preventDefault()
            const plateId = seemoreLink.getAttribute('data-plateid')
            if (!plateId) return
            const recydywaElement = document.getElementById('recydywa')
            const recydywaContent = document.querySelector('#recydywa .popup-content')
            if (recydywaContent) recydywaContent.innerHTML = '<div class="loader"></div>'
            if (recydywaElement) recydywaElement.style.display = 'block'
            const api = new Api(`/app/recydywa/partial/${encodeURIComponent(plateId)}`)
            const html = await api.getHtml()
            if (recydywaContent) recydywaContent.innerHTML = html
        }
    })

    const recydywaElement = document.getElementById('recydywa')
    if (recydywaElement) {
        document.addEventListener('keyup', e => {
            if (e.key === 'Escape') {
                recydywaElement.style.display = 'none'
            }
        })
        recydywaElement.addEventListener('click', e => {
            const target = /** @type {HTMLElement} */ (e.target)
            if (target === recydywaElement || target.classList.contains('close')) {
                recydywaElement.style.display = 'none'
            }
        })
    }
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

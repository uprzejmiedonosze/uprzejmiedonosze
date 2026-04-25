import Api from '../lib/Api'
import { num } from '../lib/format'

export async function updateRecydywa(appId) {
    const overlay = document.querySelector(".recydywa-overlay")
    const details = document.querySelector(".recydywa-details")

    const recydywa = await getRecydywa(appId)
    const appsCnt = recydywa?.apps.length || 0
    const onlyme = (recydywa?.apps.filter(item => !item.owner).length || 0) == 0
    const uniqueUsers = new Set(recydywa?.apps.map(item => item.email)).size

    if (appsCnt == 0) {
        if (overlay) {
            /** @type {HTMLElement} */ (overlay).style.display = 'none'
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
        return
    }

    if (overlay) {
        /** @type {HTMLElement} */ (overlay).style.display = 'block'
    }
    if (recydywa.lastTicket && details) {
        /** @type {HTMLElement} */ (details).style.visibility = "visible"
        const lastTicketElement = details.querySelector('.recydywa-lastTicket')
        if (lastTicketElement) lastTicketElement.innerHTML = recydywa.lastTicket
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

        if (recydywa.lastTicket && details) {
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

        if (recydywa.lastTicket && details) {
            const headerElement = details.querySelector('.recydywa-header')
            if (headerElement) {
                headerElement.textContent = 'Pojazd zgłoszony '
                    + num(appsCnt, ['razy', 'raz', 'razy'])
                    + ', przez '
                    + num(uniqueUsers, ['użytkowników', 'innego użytkownika', 'użytkowników'])
            }
        }
    }

    if (recydywa.isPresentInGallery && details) {
        const galleryLinkElement = details.querySelector('.recydywa-galleryLink')
        if (galleryLinkElement) {
            galleryLinkElement.innerHTML = `<a target="_blank" href="https://galeria.uprzejmiedonosze.net/tagged/${recydywa.plateId}">zobacz galerię</a>`
        }
    }
}

async function getRecydywa(appId) {
    const api = new Api(`/api/app/${appId}/recydywa`)
    try {
        return await api.getJson()
    } catch (err) {
        console.error('Error fetching data:', err)
        return null;
    }
}

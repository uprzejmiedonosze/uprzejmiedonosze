import $ from "jquery"

import Api from '../lib/Api'
import { num } from '../lib/format'

export async function updateRecydywa(appId) {
    const $overlay = $(".recydywa-overlay")
    const $details = $(".recydywa-details")

    const recydywa = await getRecydywa(appId)
    const appsCnt = recydywa?.apps.length || 0
    const onlyme = (recydywa?.apps.filter(item => !item.owner).length || 0) == 0
    const uniqueUsers = new Set(recydywa?.apps.map(item => item.email)).size

    if (appsCnt == 0) {
        $overlay.hide()
        $details.hide()
        return
    }

    $overlay.show()
    if (recydywa.lastTicket) {
        $details.show()
        $details.find('.recydywa-lastTicket').html(recydywa.lastTicket);
    }

    if (onlyme) {
        $overlay.addClass('onlyme')
        $details.addClass('onlyme')

        $overlay.find('.recydywa-appscnt').text(
            'Zgłosiłeś dotąd ' + num(appsCnt, ['razy', 'raz', 'razy'])
        )
        if (recydywa.lastTicket) {
            $details.find('.recydywa-header').text('Pojazd zgłoszony '
                + num(appsCnt, ['razy', 'raz', 'razy'])
                + ', wyłącznie przez Ciebie');
        }
    } else {
        $overlay.removeClass('onlyme')
        $details.removeClass('onlyme')

        $overlay.find('.recydywa-userscnt').text(num(uniqueUsers, ['osób zgłosiło', 'osoba zgłosiła', 'osoby zgłosiły']))
        $overlay.find('.recydywa-appscnt').text(
            num(appsCnt, ['wykroczeń', 'wykroczenie', 'wykroczenia'])
        )

        if (recydywa.lastTicket) {
            $details.find('.recydywa-header').text('Pojazd zgłoszony '
                + num(appsCnt, ['razy', 'raz', 'razy'])
                + ', przez '
                + num(uniqueUsers, ['użytkowników', 'innego użytkownika', 'użytkowników']));
        }
    }


    if (recydywa.isPresentInGallery) {
        $details.find('.recydywa-galleryLink')
            .html(`<a href="https://galeria.uprzejmiedonosze.net/tagged/DW9096Y">zobacz galerię</a>`);
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

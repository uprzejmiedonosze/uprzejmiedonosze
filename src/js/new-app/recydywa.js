import $ from "jquery"

import Api from '../lib/Api'
import { num } from '../lib/format'

export async function updateRecydywa(appId) {
    const $recydywa = $("#recydywa")

    const recydywa = await getRecydywa(appId)
    const appsCnt = recydywa?.apps.length || 0
    const youAreNotAlone = recydywa?.apps.filter(item => !item.owner).length || 0
    const uniqueUsers = new Set(recydywa?.apps.map(item => item.email)).size

    if (appsCnt > 0) {
        $recydywa.find('.recydywa-appscnt').text(
            num(appsCnt, ['wykroczeń', 'wykroczenie', 'wykroczenia'])
            + (youAreNotAlone == 0 ? ' (tylko twoje)' : '')
        )
        $recydywa.show()
        $recydywa.find('.recydywa-userscnt').hide()
        if (youAreNotAlone > 0) {
            $recydywa.find('.recydywa-userscnt').text(num(uniqueUsers, ['osób zgłosiło', 'osoba zgłosiła', 'osoby zgłosiły'])).show()
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

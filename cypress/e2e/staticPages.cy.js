describe('Static pages no session', function() {
    before(() => {
        cy.clearCookie('UDSESSIONID')
        cy.clearCookie('PHPSESSID')
    })

    beforeEach(() => {
        // @ts-ignore
        cy.loadConfig()
        cy.visit('/')
    })

    it('/', () => {
        cy.contains('Zgłoś nieprawidłowe parkowanie')
        // Desktopowa nawigacja headera + CTA aplikacji (zamiast dawnego menu).
        cy.get('.mpr-navbar-links').contains('Poradnik')
        cy.get('.mpr-navbar-cta').contains('Aplikacja')
        // Stopka niesie pełną nawigację serwisu.
        cy.get('footer').contains('Regulamin')
    })

    it('/ » regulamin', () => {
        cy.footerLink('Regulamin')
        cy.contains('anonimowe dane statystyczne')
        cy.contains('Aktualizacja 2024-03-26')
    })

    it('/ » historia', () => {
        cy.footerLink('Historia zmian')
        cy.contains('Poniedziałek, 13 lipca 2020')
        cy.contains(', 0').should('not.exist')

        const months = [
            'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December'
        ]

        months.forEach(month => {
            cy.contains(month).should('not.exist')
        })

        const weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']

        months.forEach(weekdays => {
            cy.contains(weekdays).should('not.exist')
        })
    })

    it('/404', () => {
        cy.visit('/non-existing-page', { failOnStatusCode: false })
        cy.contains('404')
    })

    it('/ » dla programistów', () => {
        cy.footerLink('Dla programistów')
        cy.contains('Jesteś programistą')
    })

    it('/ » polityka prywatności', () => {
        cy.footerLink('Polityka prywatności')
        cy.contains('szymon@uprzejmiedonosze.net')
    })

    it('/ » bezpieczeństwo', () => {
        cy.footerLink('Bezpieczeństwo')
        cy.contains('zero-knowledge security')
    })

    it('/ » naklejki', () => {
        cy.footerLink('Kup naklejki')
        cy.contains('naklejki ROBISZ TO ŹLE')
    })

    it('/ » jak sprawdzić SM', () => {
        cy.footerLink('Jak sprawdzić efekty pracy SM?')
        cy.contains('Ścieżka dostępu do informacji Sieci Obywatelskiej Watchdog')
    })

    it('/ » kontakt', () => {
        cy.footerLink('Kontakt')
        cy.contains('Grupa wsparcia na Facebooku')
    })

    it('/ » Jak zgłaszać', () => {
        cy.footerLink('Poradnik „jak zgłaszać”')
        cy.contains('przez Uprzejmie Donoszę')
    })

    it('/ » Jak zgłaszać » dzwoń jak szeryf', () => {
        cy.footerLink('Poradnik „jak zgłaszać”')
        cy.contains('na całych patoparkingach').click()
        cy.contains('sprawdzaj efekty pracy SM').click()
        cy.contains('Proszę o przekazanie ww.')
    })

    it('/ » faq', () => {
        cy.footerLink('Najczęstsze pytania')
        cy.contains('Szczecin')
    })

    it('/ » faq » aplikacja', () => {
        cy.footerLink('Najczęstsze pytania')
        cy.contains('wygodny skrót').click()
        cy.contains('Zainstaluj przeglądarkę')
    })

    it('/ » przesłuchanie', () => {
        cy.footerLink('Wizyta na komisariacie')
        cy.contains('Czy straż miejska wezwie mnie na przesłuchanie?')
    })

    it('/ » przesłuchanie » zwrot', () => {
        cy.footerLink('Wizyta na komisariacie')
        cy.contains('jak zniechęcić SM').click()
        cy.contains('Nieznany jest przypadek przekonania SM')
    })

    it('/ » Statystyki', () => {
        cy.footerLink('Statystyki')
        cy.contains('Nowe zgłoszenia oraz nowi użytkownicy')
    })

})

describe('Static pages logged in', function() {
    before(() => {
        cy.clearCookie('UDSESSIONID')
        cy.clearCookie('PHPSESSID')
    })

    beforeEach(() => {
        // @ts-ignore
        cy.loadConfig()
        // @ts-ignore
        cy.login()
        cy.visit('/')
    })

    it('/app', function () {
        // Gamifikacja (poziomy/odznaki, powitanie) żyje teraz na dashboardzie /app,
        // nie na marketingowej stronie głównej.
        cy.visit('/app')
        // Names/descs in JSON are feminine; derive sex-invariant stem for m/f/? variants on page
        const sexStem = (s) => {
            const obrMatch = s.match(/^Obrończyni\s+(.+)/)
            if (obrMatch) return obrMatch[1]
            return s.replace(/czka$/, '').replace(/ka$/, '').replace(/a$/, '')
        }
        cy.contains('Cześć Tester,')
        Object.values(this.levels).forEach(level => cy.contains(new RegExp(sexStem(level.desc))))
        Object.values(this.badges).forEach(badge => cy.contains(new RegExp(sexStem(badge.name))))
        cy.get('.badge').should('not.have.class', 'active')
        cy.contains('wkurzony, ale walczący')
    })

    it('/ » Jak zgłaszać » dzwoń jak szeryf', () => {
        cy.footerLink('Poradnik „jak zgłaszać”')
        cy.contains('na całych patoparkingach').click()
        cy.contains('sprawdzaj efekty pracy SM').click()
        cy.contains('Proszę o przekazanie ww.')
        cy.contains('adres poczty elektronicznej: e@nieradka.net.')
    })

    it('/ » patronite', () => {
        cy.footerLink('Patronite')
        cy.contains('ponad 16 000 zgłoszeń')
    })

})

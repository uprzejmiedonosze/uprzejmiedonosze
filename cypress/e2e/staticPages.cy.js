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
        cy.contains('Zgłoś nieprawidłowe parkowanie!')
        cy.contains('Suppi')

        cy.get('label.menu > .button-toggle').click()
        cy.contains('Zaloguj')
        cy.contains('zarejestruj')
    })

    it('/ » regulamin', () => {
        cy.contains('Regulamin').click()
        cy.contains('anonimowe dane statystyczne')
        cy.contains('Aktualizacja 2024-03-26')
    })

    it('/ » historia', () => {
        cy.contains('Historia zmian').click()
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
        cy.contains('Dla programistów').click()
        cy.contains('Jesteś programistą')
    })

    it('/ » polityka prywatności', () => {
        cy.contains('Polityka prywatności').click()
        cy.contains('szymon@uprzejmiedonosze.net')
    })

    it('/ » bezpieczeństwo', () => {
        cy.contains('Bezpieczeństwo').click()
        cy.contains('zero-knowledge security')
    })

    it('/ » naklejki', () => {
        cy.contains('Kup naklejki').click()
        cy.contains('naklejki ROBISZ TO ŹLE')
    })

    it('/ » zażalenie', () => {
        cy.contains('Zażalenie na brak mandatu').click()
        cy.contains('Masz zaledwie 7 dni')
    })

    it('/ » e-doręczenie', () => {
        cy.contains('e-Doręczenia').click()
        cy.contains('Wskaż odpowiednią jednostkę.')
    })

    it('/ » jak sprawdzić SM', () => {
        cy.contains('Jak sprawdzić efekty pracy SM?').click()
        cy.contains('Ścieżka dostępu do informacji Sieci Obywatelskiej Watchdog')
    })

    it('/menu » kontakt', () => {
        cy.get('label.menu > .button-toggle').click()
        cy.contains('Kontakt').click()
        cy.contains('Grupa wsparcia na Facebooku')
    })

    it('/menu » Jak zgłaszać', () => {
        cy.get('label.menu > .button-toggle').click()
        cy.contains('Jak zgłaszać').click()
        cy.contains('przez Uprzejmie Donoszę')
    })

    it('/menu » Jak zgłaszać » dzwoń jak szeryf', () => {
        cy.get('label.menu > .button-toggle').click()
        cy.contains('Jak zgłaszać').click()
        cy.contains('na całych patoparkingach').click()
        cy.contains('sprawdzaj efekty pracy SM').click()
        cy.contains('Proszę o przekazanie ww.')
    })

    it('/menu » przepisy', () => {
        cy.get('label.menu > .button-toggle').click()
        cy.contains('Przepisy').click()
        cy.contains('Pojazd może być usunięty z drogi na koszt właściciela')
    })

    it('/menu » faq', () => {
        cy.get('label.menu > .button-toggle').click()
        cy.contains('FAQ').click()
        cy.contains('Konstantynów Łódzki')
        cy.contains('Szczecin')
    })

    it('/menu » faq » aplikacja', () => {
        cy.get('label.menu > .button-toggle').click()
        cy.contains('FAQ').click()
        cy.contains('wygodny skrót').click()
        cy.contains('Zainstaluj przeglądarkę')
    })

    it('/menu » przesłuchanie', () => {
        cy.get('label.menu > .button-toggle').click()
        cy.contains('Wizyta w SM').click()
        cy.contains('Czy straż miejska wezwie mnie na przesłuchanie?')
    })

    it('/menu » przesłuchanie » zwrot', () => {
        cy.get('label.menu > .button-toggle').click()
        cy.contains('Wizyta w SM').click()
        cy.contains('jak zniechęcić SM').click()
        cy.contains('Nieznany jest przypadek przekonania SM')
    })

    it('/menu » Statystyki', () => {
        cy.get('label.menu > .button-toggle').click()
        cy.contains('Statystyki').click()
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

    it('/', function () {
        cy.contains('Cześć Tester,')
        Object.values(this.levels).forEach(level => {
            cy.contains(level.desc)
        })
        Object.values(this.badges).forEach(badge => {
            cy.contains(badge.name)
        })
        cy.get('.badge').should('not.have.class', 'active')
        cy.contains('wkurzony, ale walczący')
    })

    it('/menu » Jak zgłaszać » dzwoń jak szeryf', () => {
        cy.get('label.menu > .button-toggle').click()
        cy.contains('Jak zgłaszać').click()
        cy.contains('na całych patoparkingach').click()
        cy.contains('sprawdzaj efekty pracy SM').click()
        cy.contains('Proszę o przekazanie ww.')
        cy.contains('adres poczty elektronicznej: e@nieradka.net.')
    })

    it('/menu » patronite', () => {
        cy.get('label.menu > .button-toggle').click()
        cy.contains('Zostań Patronem').click()
        cy.contains('ponad 8000 zgłoszeń')
    })

})
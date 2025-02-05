describe('Static pages no session', function() {
    before(() => {
        cy.clearCookie('UDSESSIONID')
        cy.clearCookie('PHPSESSID')
    })

    beforeEach(() => {
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
    })

    it('/ » historia', () => {
        cy.contains('Historia zmian').click()
        cy.contains('Poniedziałek, 13 lipca 2020')
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
        cy.contains('zwrot środków za przesłuchanie').click()
        cy.contains('Zwrot utraconego dochodu')
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
        cy.loadConfig()
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

})
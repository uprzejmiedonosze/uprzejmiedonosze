describe('Checks static pages', function() {
    before(() => {
        cy.clearCookie('UDSESSIONID')
    })

    beforeEach(() => {
        cy.loadConfig()
        cy.visit('/')
        cy.contains('Menu').click()
    })

    it('Visits main page', () => {
        cy.contains('Zgłoś nieprawidłowe parkowanie!')
        cy.contains('Suppi')

        cy.contains('– galeria')

        cy.contains('Zaloguj')
        cy.contains('zarejestruj')
    })

    it('Visits przepisy', () => {
        cy.contains('– przepisy').click()
        cy.contains('Pojazd może być usunięty z drogi na koszt właściciela')

    })

    it('Visits faq', () => {
        cy.contains('faq').click()
        cy.contains('Konstantynów Łódzki')
        cy.contains('Szczecin')
    })

    it('Visits regulamin', () => {
        cy.contains('regulamin').click()
        cy.contains('anonimowe dane statystyczne')
    })

    it('Visits statystyki', () => {
        cy.contains('statystyki').click()
        cy.contains('Nowe zgłoszenia oraz nowi użytkownicy')
    })

    it('Visits historia', () => {
        cy.contains('historia').click()
        cy.contains('Poniedziałek, 13 lipca 2020')
    })

    it('Visits o projekcie', () => {
        cy.contains('o projekcie').click()
        cy.contains('Bitbucket')
    })

    it('Visits zwrot za przesłuchanie', () => {
        cy.visit('/zwrot-za-przesluchanie.html')
        cy.contains('Jak uzyskać zwrot środków')
    })

    it('Visits jak zgłosić nielegalne parkowanie', () => {
        cy.visit('/jak-zglosic-nielegalne-parkowanie.html')
        cy.contains('przez Uprzejmie Donoszę')
    })

    it('Visits dzwoń jak szeryf', () => {
        cy.contains('dzwoń do SM').click()
        cy.contains('Dzwonisz do Straży Miejskiej')
        cy.contains('Proszę o przekazanie ww.')
    })

    it('Visits dzwoń jak szeryf after login', () => {
        cy.login()
        cy.contains('dzwoń do SM').click()
        cy.contains('adres poczty elektronicznej: e@nieradka.net.')
    })

    it('Visits main page after login', function () {
        cy.login()
        cy.visit('/')
        cy.contains('Cześć Tester')
        Object.values(this.levels).forEach(level => {
            cy.contains(level.desc)
        })
        Object.values(this.badges).forEach(badge => {
            cy.contains(badge.name)
        })
        cy.get('.badge').should('not.have.class', 'active')
        cy.contains('wkurzony, ale walczący')
    })

})
describe('Checks static pages', () => {
    before(() => {
        cy.clearCookie('PHPSESSID')
    })

    beforeEach(() => {
        cy.visit('/')
        cy.contains('Menu').click()
    })

    it('Visits main page', () => {
        cy.contains('Spróbuj')
        cy.contains('Sprawdź jak to działa')
        cy.contains('Zgłoś')
        cy.contains('historię zmian')
        cy.contains('polityką prywatności')
        cy.contains('jak można pomóc')

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

    it('Visits dzwoń jak szeryf', () => {
        cy.contains('dzwoń do SM').click()
        cy.contains('Dzwonisz do Straży Miejskiej')
        cy.contains('Proszę o przekazanie ww.')
    })

    it('Visits dzwoń jak szeryf', () => {
        cy.login()
        cy.contains('dzwoń do SM').click()
        cy.contains('adres poczty elektronicznej: e@nieradka.net.')
    })

})
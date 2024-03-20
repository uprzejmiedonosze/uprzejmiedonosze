describe('API:automated (Poznań)', () => {
    before(() => {
        cy.initDB()
        cy.login()
        cy.goToNewAppScreen()
    })

    beforeEach(() => {
        cy.loadConfig()
    })

    it('creates application', function () {
        cy.uploadOKImages('poznan.jpg')
        cy.setAppCategory(this.categories)
        cy.get('#geo', { timeout: 1000 }).should('have.class', 'ui-icon-location')
        cy.get('#form-submit').click()
        cy.sendApp()
        cy.contains('Wystąpił błąd').should('not.exist')
    })

    it('checks thank you screen', function () {
        cy.contains('Dziękujemy za wysłanie zgłoszenia')
        cy.contains('Jeszcze raz')
        cy.contains(this.sm['Poznań'].address[0])
    })

    it('checks my apps screen', function () {
        cy.contains('Menu').click()
        cy.contains('Moje zgłoszenia').click({force: true})
        cy.contains(this.config.address.poznan).click()
        cy.contains(this.sm['Poznań'].address[0].replace('Straż Miejska', 'SM'))
        cy.contains('zmień status').click()
        cy.contains('Zmień status zgłoszenia z Potwierdzone na')
    })
})

describe('API:Mail (Wrocław)', () => {
    before(() => {
        cy.initDB()
        cy.login()
    })

    beforeEach(() => {
        cy.loadConfig()
    })

    it('creates application', function () {
        cy.goToNewAppScreen()
        cy.uploadOKImages('wroclaw.jpg')
        cy.wait(1000)
        cy.get('.mapboxgl-ctrl-zoom-out').click()
        cy.setAppCategory(this.categories)
        cy.get('#geo', { timeout: 1000 }).should('have.class', 'ui-icon-location')
        cy.get('#form-submit').click()
        cy.sendApp()
        cy.contains('Wystąpił błąd').should('not.exist')
    })

    it('checks thank you screen', function () {
        cy.contains('Dziękujemy za wysłanie zgłoszenia')
        cy.contains('Jeszcze raz')
        cy.contains(this.sm['Wrocław'].address[0].replace('Straż Miejska', 'SM'))
    })

    it('checks my apps screen', function () {
        cy.contains('Menu').click()
        cy.contains('Moje zgłoszenia').click({force: true})
        cy.contains(this.config.address.wroclaw.replace('Plac Generała ', '')).click()
        cy.contains('zmień status').click()
        cy.contains('Zmień status zgłoszenia z Wysłane na')
    })

})

describe('Missing SM (Poniatowa)', () => {
    before(() => {
        cy.initDB()
        cy.login()
    })

    beforeEach(() => {
        cy.loadConfig()
    })

    it('creates application', function () {
        cy.goToNewAppScreen()
        cy.uploadOKImages('poniatowa.jpg')
        cy.setAppCategory(this.categories)
        cy.get('#geo', { timeout: 1000 }).should('have.class', 'ui-icon-location')
        cy.get('#form-submit', { timeout: 5000 }).click()
        cy.contains('Zapisz!').click()
        cy.contains('Wystąpił błąd').should('not.exist')
    })

    it('checks thank you screen', function () {
        cy.contains('Jeszcze raz')
        cy.contains('Niestety, dla twojego miasta nie mamy jeszcze zapisanego adresu e-mail SM')
    })

    it('checks my apps screen', function () {
        cy.contains('Menu').click()
        cy.contains('Moje zgłoszenia').click({force: true})
        cy.contains(this.config.address.poniatowa).click()
        cy.contains('Wyślij zgłoszenie')
        cy.contains('edytuj')
        cy.contains('zmień status').click()
        cy.contains('Zmień status zgłoszenia z Nowe na')
    })

    it('checks send apps screen', function () {
        cy.contains('nowe').click({force: true})
        cy.contains('Menu').click({force: true})
        cy.contains('Do wysłania').click({force: true})
        cy.contains('Pobierz paczkę zgłoszeń').should('not.exist')
        cy.contains('Brak danych Straży Miejskiej Poniatowa')
    })
})

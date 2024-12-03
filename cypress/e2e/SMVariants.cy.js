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
        cy.get('input[data-type="geo"]', { timeout: 1000 }).should('not.have.class', 'error').should('not.have.class', 'clock')
        cy.get('#form-submit').click()
        cy.sendApp()
        cy.contains('Wystąpił błąd').should('not.exist')
    })

    it('checks thank you screen', function () {
        cy.contains('Dziękujemy za wysłanie zgłoszenia')
        cy.contains('Jeszcze raz')
    })

    it('checks my apps screen', function () {
        cy.get('label.menu > .button-toggle').click()
        cy.contains('Moje zgłoszenia').click({force: true})
        cy.contains(this.config.address.poznan).click()
        cy.contains(this.sm['Poznań'].address[0].replace('Straż Miejska', 'SM'))
        cy.contains('POTWIERDZONE')
        cy.contains('ZMIEŃ').click()
        cy.contains('Kierowca dostał mandat')
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
        cy.get('.mapboxgl-ctrl-zoom-out').click({force: true})
        cy.setAppCategory(this.categories)
        cy.get('input[data-type="geo"]', { timeout: 1000 }).should('not.have.class', 'error').should('not.have.class', 'clock')
        cy.get('#form-submit').click()
        cy.sendApp()
        cy.contains('Wystąpił błąd').should('not.exist')
    })

    it('checks thank you screen', function () {
        cy.contains('Dziękujemy za wysłanie zgłoszenia')
        cy.contains('Jeszcze raz')
    })

    it('checks my apps screen', function () {
        cy.get('label.menu > .button-toggle').click()
        cy.contains('Moje zgłoszenia').click({force: true})
        cy.contains(this.config.address.wroclaw.replace('Plac Generała ', '')).click()

        cy.contains('WYSŁANE')
        cy.contains('ZMIEŃ').click()
        cy.contains('Przenieś do archiwum')
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
        cy.get('input[data-type="geo"]', { timeout: 1000 }).should('not.have.class', 'error').should('not.have.class', 'clock')
        cy.get('#form-submit', { timeout: 5000 }).click()
        cy.contains('Zapisz!').click()
        cy.contains('Wystąpił błąd').should('not.exist')
    })

    it('checks thank you screen', function () {
        cy.contains('Jeszcze raz')
        cy.contains('Niestety, dla twojego miasta nie mamy jeszcze zapisanego adresu e-mail SM')
    })

    it('checks my apps screen', function () {
        cy.get('label.menu > .button-toggle').click()
        cy.contains('Moje zgłoszenia').click({force: true})
        cy.contains(this.config.address.poniatowa).click()
        cy.contains('Wyślij zgłoszenie')
        cy.contains('edytuj')
        cy.contains('NOWE')
        cy.contains('ZMIEŃ').click()
        cy.contains('Przenieś do archiwum')
    })

    it('checks send apps screen', function () {
        cy.get('label.menu > .button-toggle').click()
        cy.contains('Do wysłania').click({force: true})

        cy.contains('Masz zgłoszenia czekające na wysłanie')

        cy.intercept('GET', 'short-**-partial.html').as('appDetails')
        cy.get('.application-short.confirmed h3')
            .should('be.visible').click()
        cy.wait('@appDetails')

        cy.contains('Wyślij zgłoszenie').click()
        
        cy.contains('Brak danych Straży Miejskiej Poniatowa')
    })
})

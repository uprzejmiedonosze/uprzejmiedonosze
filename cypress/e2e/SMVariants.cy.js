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
        cy.uploadOKImages()
        cy.setAppCategory(this.categories)
        cy.get('#lokalizacja').clear().type('Taczaka 20, Poznań')
        cy.wait(500)
        cy.get('.pac-container .pac-item', { timeout: 5000 }).first().click()
        cy.get('#geo').should('have.class', 'ui-icon-location')
        cy.get('#form-submit').click()
        cy.contains('Wyślij do').click()
        cy.contains('Wystąpił błąd').should('not.exist')
    })

    it('checks thank you screen', function () {
        cy.get('.afterSend', { timeout: 30000 }).should('be.visible')
        cy.contains('Dziękujemy za wysłanie zgłoszenia')
        cy.contains('Jeszcze raz')
        cy.contains(this.sm['Poznań'].address[0])
    })

    it('checks my apps screen', function () {
        cy.contains('Menu').click()
        cy.contains('Moje zgłoszenia').click({force: true})
        cy.contains('Stanisława Taczaka 20, Poznań').click()
        cy.contains('Zmień ręcznie status zgłoszenia z Potwierdzone w SM')
        cy.contains('Dodaj do galerii')
        cy.contains('Szczegóły')
    })

    /*it('checks send apps screen', function () {
        cy.contains('Menu').click()
        cy.contains('Do wysłania').click({force: true})
        cy.contains('Na szczęście ' + this.sm['Poznań'].address[0] + ' udostępniła mechanizm')
        cy.contains('Stanisława Taczaka 20, Poznań').click()
        cy.contains('Wyślij do ' + this.sm['Poznań'].address[0])
        cy.contains('Zmień ręcznie status zgłoszenia z Nowe').should('not.exist')
        cy.contains('Dodaj do galerii').should('not.exist')
        cy.contains('Szczegóły').should('not.exist')
    })*/
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
        cy.uploadOKImages()
        cy.setAppCategory(this.categories)
        cy.get('#lokalizacja').clear().type('Szewska 75, Wrocław')
        cy.wait(500)
        cy.get('.pac-container .pac-item', { timeout: 5000 }).first().click()
        cy.get('#geo').should('have.class', 'ui-icon-location')
        cy.get('#form-submit').click()
        cy.contains('Wyślij do').click()
        cy.contains('Wystąpił błąd').should('not.exist')
    })

    it('checks thank you screen', function () {
        cy.get('.afterSend', { timeout: 30000 }).should('be.visible')
        cy.contains('Dziękujemy za wysłanie zgłoszenia')
        cy.contains('Jeszcze raz')
        cy.contains(this.sm['Wrocław'].address[0])
    })

    it('checks my apps screen', function () {
        cy.contains('Menu').click()
        cy.contains('Moje zgłoszenia').click({force: true})
        cy.contains('Szewska 75, Wrocław').click()
        cy.contains('Zmień ręcznie status zgłoszenia z Wysłane')
        cy.contains('Dodaj do galerii')
        cy.contains('Szczegóły')
    })

})

describe.skip('API:null (Szczecin)', () => {
    before(() => {
        cy.initDB()
        cy.login()
    })

    beforeEach(() => {
        cy.loadConfig()
    })

    it('creates application', function () {
        cy.goToNewAppScreen()
        cy.uploadOKImages()
        cy.get('#form-submit').click()
        cy.contains('Wyślij do').click()
        cy.contains('Wystąpił błąd').should('not.exist')
    })

    it('checks thank you screen', function () {
        cy.contains('Jeszcze raz')
        cy.contains('Swoje zgłoszenia musisz wysłać samodzielnie')
        cy.contains(this.sm.Szczecin.address[0])
    })

    it('checks my apps screen', function () {
        cy.contains('Menu').click()
        cy.contains('Moje zgłoszenia').click({force: true})
        cy.contains(this.config.address.address).click()
        cy.contains('Wyślij zgłoszenie')
        cy.contains('Zmień ręcznie status zgłoszenia z Nowe')
        cy.contains('Dodaj do galerii')
        cy.contains('Szczegóły')
    })

    it('checks send apps screen', function () {
        cy.contains('Menu').click()
        cy.contains('Do wysłania').click({force: true})
        cy.contains('Pobierz paczkę zgłoszeń')
        cy.contains('Uwagi na temat współpracy z ' + this.sm.Szczecin.address[0])
        cy.contains(this.config.address.address).click()
        cy.contains('Wyślij zgłoszenie').should('not.exist')
        cy.contains('Zmień ręcznie status zgłoszenia z Nowe').should('not.exist')
        cy.contains('Dodaj do galerii').should('not.exist')
        cy.contains('Szczegóły').should('not.exist')
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
        cy.uploadOKImages()
        cy.setAppCategory(this.categories)
        cy.get('#lokalizacja').clear().type('Henin 93, Poniatowa')
        cy.wait(500)
        cy.get('.pac-container .pac-item', { timeout: 5000 }).first().click()
        cy.get('#geo').should('have.class', 'ui-icon-location')
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
        cy.contains('Henin 93, Poniatowa').click()
        cy.contains('Wyślij zgłoszenie')
        cy.contains('Zmień ręcznie status zgłoszenia z Nowe')
        cy.contains('Dodaj do galerii')
        cy.contains('Szczegóły')
    })

    it('checks send apps screen', function () {
        cy.contains('Menu').click()
        cy.contains('Do wysłania').click({force: true})
        cy.contains('Pobierz paczkę zgłoszeń').should('not.exist')
        cy.contains('Henin 93, Poniatowa').click()
        cy.contains('Zmień ręcznie status zgłoszenia z Nowe')
        cy.contains('Dodaj do galerii')
        cy.contains('Szczegóły')
    })

    it('checks missing SM', function () {
        cy.contains('Wyślij zgłoszenie').click()
        cy.contains('Brak danych Straży Miejskiej', { timeout: 10000 })
    })
})

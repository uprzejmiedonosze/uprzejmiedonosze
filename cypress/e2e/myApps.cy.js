function checkAppData(config) {
    cy.contains(config.carImage.plateId)
    cy.contains(config.carImage.date)
    cy.contains(config.carImage.hour)
    cy.contains(config.address.address)
    cy.contains(config.user.name)
    cy.contains(config.user.email)
}

describe('Empty my apps', () => {
    before(() => {
        cy.initDB()
        cy.login()
    })

    it('gets to my apps screen', () => {
        cy.visit('/')
        cy.contains('Menu').click()
        cy.contains('Moje zgłoszenia').click()
    })

    it('checks my apps screen', () => {
        cy.contains('Nie masz jeszcze ani jednego zgłoszenia.')
    })
})

describe('Application screen validation', () => {
    before(() => {
        cy.login()
    })

    beforeEach(() => {
        cy.loadConfig()
    })

    it('checks new application screen', function () {
        cy.goToNewAppScreen()
        cy.get('#lokalizacja').should('have.value', this.config.address.address)
        cy.get('#plateId').should('be.empty')
        cy.get('#plateImage').should('be.hidden')
        Object.entries(this.categories).
            filter(c => c.title).
            forEach((category) => {
            const [id, def] = category
            if (def.stopAgresjiOnly)
                cy.get(`input#${id}`).should('be.disabled')
            else
                cy.get(`input#${id}`).should('be.enabled')
        })
        
        Object.entries(this.extensions).forEach((extension) => {
            const id = extension[0]
            cy.get(`input#ex${id}`).click({force: true})
        })
        Object.entries(this.extensions).forEach((extension) => {
            const id = extension[0]
            cy.get(`input#${id}`).click({force: true})
            cy.get(`input#ex${id}`).should('be.disabled')
        })
        cy.get('.datetime .ui-btn').should('not.have.class', 'ui-state-disabled')
        cy.get('#latlng').should(($input) => {
            const val = $input.val()
            expect(val).to.match(new RegExp(this.config.address.latlng))
        })
    })

    it('checks empty application validation', function () {
        cy.contains('Dalej').click()
        cy.get('.imageContainer').should('have.class', 'error')
        cy.get('#plateId').should('have.class', 'error')
    })
})

describe('Invalid images', () => {
    before(() => {
        cy.initDB()
        cy.login()
    })

    beforeEach(() => {
        cy.loadConfig()
    })

    it('loads invalid images', function () {
        cy.goToNewAppScreen()
        cy.uploadWrongImages()
    })

    it('checks application page with wrong images uploaded', function () {
        cy.get('.imageContainer').should('not.have.class', 'error')
        cy.contains("około")
        cy.contains('Podaj datę i godzinę zgłoszenia')
        cy.contains('Twoje zdjęcie nie ma znaczników geolokacji')

        cy.get('#plateImage').should('be.hidden')

        cy.get('#dp').should('have.class', 'ui-state-disabled')
        cy.get('#dm').should('not.have.class', 'ui-state-disabled')
        cy.get('#hp').should('have.class', 'ui-state-disabled')
        cy.get('#hm').should('not.have.class', 'ui-state-disabled')
        cy.get('#dm').click()
        cy.get('.datetime .ui-btn').should('not.have.class', 'ui-state-disabled')
        cy.get('.datetime .changeDatetime').should('not.be.visible')
    })
})

describe('Valid images and location', () => {
    before(() => {
        cy.initDB()
        cy.login()
    })

    beforeEach(() => {
        cy.loadConfig()
    })

    it('checks address autocomplete', function () {
        cy.goToNewAppScreen()
        cy.get('#lokalizacja').clear().type('Mazurska, Poznań')
        cy.wait(500)
        cy.get('.pac-container .pac-item', { timeout: 5000 }).first().click()
        cy.get('#geo').should('have.class', 'ui-icon-location')
        cy.get('#lokalizacja').should('have.value', 'Zagórzycka 20, Poznań')
    })

    it('uploads images', function () {
        cy.uploadOKImages()
        cy.get('.imageContainer').should('not.have.class', 'error')

        cy.get('#comment').should('have.value', 'Pojazd marki Skoda.')

        cy.get('#plateId').should('have.value', this.config.carImage.plateId)
        cy.get('#plateImage').should('be.visible')
        cy.get('#plateId').should('not.have.class', 'error')

        cy.get('#comment').should('not.have.class', 'error')

        cy.contains('Data i godzina pobrana ze zdjęcia')
        cy.contains(this.config.carImage.dateHuman)
        cy.contains(this.config.carImage.hour)
        cy.get('.datetime .changeDatetime').should('be.visible')

        cy.get('#lokalizacja').should('have.value', this.config.address.address)
    })
})

describe('Create application', () => {
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
        const firstExtension = Object.entries(this.extensions)[0]
        cy.get(`input#ex${firstExtension[0]}`).click({force: true})
    })

    it('checks confirmation screen', function () {
        cy.get('#form-submit', { timeout: 10000 }).click()
        cy.contains('Wystąpił błąd').should('not.exist')
        checkAppData(this.config)
        cy.contains('Zdjęcia wykonałem samodzielnie')
        cy.contains('proszę o niezamieszczanie')
        const firstExtension = Object.entries(this.extensions)[0]
        cy.contains(firstExtension[1].title)
    })

    it('checks thank you screen', function () {
        cy.contains('Wyślij teraz!').click()
        cy.contains('To twoje pierwsze zgłoszenie')
        cy.contains('UD/4/')
    })

    it('checks application list screen', function () {
        cy.contains('liście swoich zgłoszeń').click()
        cy.contains(this.config.address.address)
    })

    it('checks application screen', function () {
        cy.contains('Szczegóły').click({force: true})
        checkAppData(this.config)
        cy.contains('Nieaktualne dane?')
        cy.contains('Zapisanie wersji roboczej')
    })
})

describe('Edit application', () => {
    before(() => {
        cy.login()
    })

    beforeEach(() => {
        cy.loadConfig()
    })

    it('creates application', function () {
        cy.goToNewAppScreenWithoutTermsScreen()
        cy.uploadOKImages()
    })

    it('checks confirmation screen', function () {
        cy.get('#form-submit', { timeout: 10000 }).click()
        cy.contains('Wystąpił błąd').should('not.exist')
        checkAppData(this.config)
    })

    it('checks edit', function() {
        cy.get('.ui-icon-edit').click()
        cy.contains('Edytujesz zgłoszenie')
    })

    it('alters application', function() {
        cy.get('#comment').type(this.config.comment)
        cy.get('#witness').click({force: true})
        cy.contains('Zatrzymanie na drodze dla rowerów').click()
        cy.contains('(zmień datę, jeśli niepoprawna)').click()

        cy.contains("około")
        cy.contains('Podaj datę i godzinę zgłoszenia')
        cy.get('#dm').click()
        cy.get('#hp').click()

        cy.contains(this.config.carImage.dateHumanDM1)
        cy.contains(this.config.carImage.hourHumanHP1)
        cy.get('.datetime .ui-btn').should('not.have.class', 'ui-state-disabled')
        cy.get('.datetime .changeDatetime').should('not.be.visible')
    })

    it('checks altered application', function(){
        cy.get('#form-submit').click()
        cy.contains('Wystąpił błąd').should('not.exist')

        cy.contains(this.config.carImage.plateId)
        cy.contains(this.config.carImage.dateDM1)
        cy.contains(this.config.carImage.hourHumanHP1)
        cy.contains(this.config.address.address)
        cy.contains(this.config.user.name)
        cy.contains(this.config.user.email)
        cy.contains("około")

        cy.contains(this.config.comment)
        cy.contains('Nie byłem świadkiem samego momentu parkowania').should('not.exist')
    })

    it('checks thank you screen', function () {
        cy.contains('Wyślij teraz!').click()
        cy.contains('To twoje pierwsze zgłoszenie').should('not.exist')
        cy.contains('UD/4/')
    })

    it('checks application list screen', function () {
        cy.contains('liście swoich zgłoszeń').click()
        cy.contains(this.config.address.address)
        cy.get('#collapsiblesetForFilter').find('.application').should('have.length', 2)
    })

    it('checks application screen', function () {
        cy.contains('Szczegóły').click({force: true})
        cy.contains(this.config.carImage.dateDM1)
        cy.contains(this.config.carImage.hourHumanHP1)
        cy.contains('Nieaktualne dane?')
        cy.contains('Zapisanie wersji roboczej')
    })
})

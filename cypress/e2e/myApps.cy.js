// @ts-nocheck
function checkAppData(config, confirmationScreen=false) {
    let address = config.address.szczecin
    let date = config.carImage.dateHuman
    if (confirmationScreen) {
        address = address.replace(", Szczecin", "")
        date = config.carImage.dateOnConfirmation
    }
    cy.contains(config.carImage.plateId)
    cy.contains(date)
    cy.contains(address)
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
        cy.get('label.menu > .button-toggle').click()
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

        
        cy.get('#lokalizacja').should("have.value", this.config.address.address)
        cy.get('#plateId').should('be.empty')
        cy.get('#plateImage').should('be.hidden')
        Object.entries(this.categories).
            filter(c => c[1].title).
            forEach((category) => {
            const [id, def] = category
            cy.get(`input#${id}`).should('be.enabled')
        })
        
        Object.entries(this.extensions).forEach((extension) => {
            const id = extension[0]
            cy.get(`input#ex${id}`).click({force: true})
        })
        Object.entries(this.extensions).forEach((extension) => {
            const id = extension[0]
            cy.get(`input#${id}`).click({force: true})
            cy.get(`input#ex${id} + label`).should('have.class', 'disabled')
        })
        cy.get('input#datetime').should('have.attr', 'readonly')
        cy.get('#address').should(($input) => {
            const address = JSON.parse($input.val())
            const latlng = `${address.lat},${address.lng}`
            expect(latlng).to.match(new RegExp(this.config.address.latlng))
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
        cy.wait(1000)
        cy.uploadWrongImages()
    })

    it('checks application page with wrong images uploaded', function () {
        cy.get('.imageContainer').should('not.have.class', 'error')
        cy.contains('Podaj datę i godzinę zgłoszenia')
        cy.contains('Twoje zdjęcie nie ma znaczników geolokacji')

        cy.get('#plateImage').should('be.hidden')
        cy.get('.changeDatetime').should('not.be.visible')
    })
})

describe('Valid images and location', () => {
    before(() => {
        cy.login()
    })

    beforeEach(() => {
        cy.loadConfig()
    })

    it('uploads images', function () {
        cy.uploadOKImages()
        cy.get('input[data-type="geo"]', { timeout: 1000 }).should('not.have.class', 'error').should('not.have.class', 'clock')
        cy.get('.imageContainer').should('not.have.class', 'error')

        cy.get('#comment').should('have.value', 'Pojazd marki Skoda.')

        cy.get('#plateId').should('have.value', this.config.carImage.plateId)
        cy.get('#plateImage').should('be.visible')
        cy.get('#plateId').should('not.have.class', 'error')

        cy.get('#comment').should('not.have.class', 'error')

        cy.contains('Data i godzina pobrana ze zdjęcia')
        cy.get('#datetime').should('have.value', this.config.carImage.dateISO)
        cy.get('.changeDatetime').should('be.visible')

        cy.get('#lokalizacja').should("have.value", this.config.address.szczecin)
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
        cy.setAppCategory(this.categories)
        const firstExtension = Object.entries(this.extensions)[0]
        cy.get(`input#ex${firstExtension[0]}`).click({force: true})
        cy.get('input[data-type="geo"]', { timeout: 1000 }).should('not.have.class', 'error').should('not.have.class', 'clock')
        cy.get('#form-submit', { timeout: 10000 }).click()
    })

    it('checks confirmation screen', function () {
        cy.contains('Wystąpił błąd').should('not.exist')
        checkAppData(this.config, true)
        const firstExtension = Object.entries(this.extensions)[0]
        cy.contains(firstExtension[1].title)
    })

    it('checks thank you screen', function () {
        cy.sendApp()
        cy.contains('To twoje pierwsze zgłoszenie')
        cy.contains('UD/4/')
    })

    it('checks application list screen', function () {
        cy.visit('/moje-zgloszenia.html')
        cy.location('pathname').should('include', '/moje-zgloszenia.html');
        cy.intercept('GET', 'short-**-partial.html').as('appDetails')
        cy.get('.application-short.sending h3')
            .should('be.visible').click()
        cy.wait('@appDetails')
    })

    it('checks application screen', function () {
        cy.get('.images-slider a:first').invoke('removeAttr', 'target').click({force: true})
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
        cy.setAppCategory(this.categories)
        cy.get('input[data-type="geo"]', { timeout: 1000 }).should('not.have.class', 'error').should('not.have.class', 'clock')
        cy.get('#form-submit', { timeout: 10000 }).click()
    })

    it('checks confirmation screen', function () {
        cy.contains('Wystąpił błąd').should('not.exist')
        checkAppData(this.config, true)
    })

    it('checks edit', function() {
        cy.contains('Edytuj').click()
        cy.contains('Edytujesz zgłoszenie')
    })

    it('alters application', function() {
        cy.get('#comment').type(this.config.comment)
        cy.get('#witness').click({force: true})
        cy.contains('Droga dla rowerów').click()

        cy.contains('Data i czas zgłoszenia')
        cy.get('#datetime').type(this.config.carImage.dateISOAltered)

        cy.get('#datetime').should('have.value', this.config.carImage.dateISOAltered)
        cy.get('.changeDatetime').should('not.be.visible')
    })

    it('checks altered application', function(){
        cy.get('#form-submit').click()
        cy.contains('Wystąpił błąd').should('not.exist')

        cy.contains(this.config.carImage.plateId)
        cy.contains("2222 środa, 2 lutego 2022")
        cy.contains(this.config.address.szczecin.replace(", Szczecin", ""))
        cy.contains(this.config.user.name)
        cy.contains(this.config.user.email)

        cy.contains(this.config.comment)
        cy.contains('Nie byłem świadkiem samego momentu parkowania').should('not.exist')
    })

    it('checks thank you screen', function () {
        cy.sendApp()
        cy.contains('To twoje pierwsze zgłoszenie').should('not.exist')
        cy.contains('UD/4/')
    })

    it('checks application list screen', function () {
        cy.visit('/moje-zgloszenia.html')
        cy.location('pathname').should('include', '/moje-zgloszenia.html');
        cy.intercept('GET', 'short-**-partial.html').as('appDetails')
        cy.get('.application-short.sending h3:first')
            .should('be.visible').click()
        cy.wait('@appDetails')
        cy.contains(this.config.address.szczecin)
        cy.get('#apps-list').find('.application').should('have.length', 2)
    })

    it('checks application screen', function () {
        cy.get('.images-slider a:first').invoke('removeAttr', 'target').click({force: true})
        cy.contains(this.config.carImage.dateHumanAltered)
        cy.contains('Nieaktualne dane?')
        cy.contains('Zapisanie wersji roboczej')
    })
})

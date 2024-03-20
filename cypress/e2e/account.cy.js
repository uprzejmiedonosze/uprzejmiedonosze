describe('Create account', () => {
  before(() => {
    cy.cleanDB()
    cy.login()
  })

  beforeEach(() => {
    cy.loadConfig()
  })

  it('Check if account is not active before registration', function () {
    cy.visit('/')
    cy.contains('Menu').click()
    cy.contains('Nowe zgłoszenie').click({force: true})
    cy.contains('Dane konta')
    cy.contains('Czy chcesz dołączać do zgłoszenia')
    cy.contains('Chcę wysyłać swoje zgłoszenia').should('not.exist')

    cy.contains('Menu').click()
    cy.contains('Moje zgłoszenia').click({force: true})
    cy.contains('Dane konta')
  })

  it('Check registration form validation', function () {
    cy.visit('/')
    cy.contains('Menu').click()
    cy.contains('Nowe zgłoszenie').click({force: true})
    cy.contains('Dane konta')

    cy.contains('Potwierdź').click()
    cy.get('#address').should('have.class', 'error')
    cy.get('#msisdn').should('not.have.class', 'error')

    cy.get('#name').clear()
    cy.contains('Potwierdź').click()
    cy.get('#name').should('have.class', 'error')

    cy.get('#name').clear().type('bla')
    cy.contains('Potwierdź').click()
    cy.get('#name').should('have.class', 'error')

    cy.get('#address').clear().type('blablabla')
    cy.contains('Potwierdź').click()
    cy.get('#address').should('have.class', 'error')
    cy.get('#address').clear().type(this.config.user.address)
    cy.get('#msisdn').focus()
    cy.get('#address').should('not.have.class', 'error')
  })

  it('Register', function () {
    cy.visit('/')
    cy.contains('Menu').click()
    cy.contains('Nowe zgłoszenie').click({force: true})
    cy.contains('Dane konta')

    cy.get('#name').clear().type(this.config.user.name)
    cy.get('#address').clear().type(this.config.user.address)
    cy.contains('Potwierdź').click()
    cy.contains('Regulamin')
    cy.contains('Uprzejmie Donoszę pozwala na informowanie Straży Miejskiej')
  })

  it('Check terms screen', function () {
    cy.visit('/')
    cy.contains('Menu').click()
    cy.contains('Nowe zgłoszenie').click({force: true})
    cy.contains('Dane konta').should('not.exist')

    cy.contains('Regulamin')

    cy.contains('Dalej').click()
    cy.contains('Menu').click()
    cy.contains('Nowe zgłoszenie').click({force: true})
    cy.contains('Regulamin').should('not.exist')
  })
})

describe('Update account', () => {
  before(() => {
    cy.login()
  })

  beforeEach(() => {
    cy.loadConfig()
  })

  it('Check default', function () {
    cy.visit('/')
    cy.contains('Menu').click()
    cy.contains('Edycja konta').click({force: true})
    cy.contains('Zaktualizuj konto')
    cy.contains('Czy chcesz dołączać do zgłoszenia')
    cy.contains('Chcę wysyłać swoje zgłoszenia')
    cy.contains('na komendę')

    cy.get('#exposeData-N').should('be.checked')
    cy.get('#stopAgresji-SM').should('be.checked')
  })

  it('New app, default settings', function () {
    cy.visit('/')
    cy.contains('Menu').click()
    cy.contains('Nowe zgłoszenie').click()
    cy.uploadOKImages()
    cy.setAppCategory(this.categories)
    cy.get('#geo', { timeout: 1000 }).should('have.class', 'ui-icon-location')
    cy.get('#form-submit').click()

    cy.contains('Równocześnie proszę o niezamieszczanie w protokole danych dotyczących mojego miejsca zamieszkania, nr. telefonu i adresu e-mail.')
    cy.contains('zostanie za chwilę wysłane')
    cy.contains(this.sm.Szczecin.address[0])
    cy.sendApp()

    cy.contains('Uwagi do współpracy')
    cy.contains('To twoje pierwsze zgłoszenie')

    cy.visit('/')
    cy.contains('Menu').click()
    cy.contains('Moje zgłoszenia').click()
    cy.contains('Mazurska').click()
    cy.contains('Wysłano do SM Szczecin')
  })

  it('Set opposite settings', function () {
    cy.visit('/')
    cy.contains('Menu').click()
    cy.contains('Edycja konta').click({force: true})

    cy.get('#exposeData-Y').check({force: true})
    cy.get('#stopAgresji-SA').check({force: true})

    cy.contains('Potwierdź').click()
    cy.visit('/')
    cy.contains('Menu').click()
    cy.contains('Edycja konta').click({force: true})

    cy.get('#exposeData-Y').should('be.checked')
    cy.get('#stopAgresji-SA').should('be.checked')
  })

  it('New app, opposite settings', function () {
    cy.visit('/')
    cy.contains('Menu').click()
    cy.contains('Nowe zgłoszenie').click()
    cy.uploadOKImages()
    Object.entries(this.categories)
      .filter(c => c[1].title)
      .forEach((category) => cy.get(`input#${category[0]}`).should('be.enabled'))
    cy.setAppCategory(this.categories)
    cy.get('#geo', { timeout: 1000 }).should('have.class', 'ui-icon-location')
    cy.get('#form-submit').click()

    cy.contains('Równocześnie proszę o niezamieszczanie w protokole danych dotyczących mojego miejsca zamieszkania, nr. telefonu i adresu e-mail.').should('not.exist')
    cy.contains('KP Szczecin Niebuszewo')
    cy.sendApp()

    cy.contains('Uwagi do współpracy z Komisariat Policji Szczecin Niebuszewo')
    cy.contains('To twoje pierwsze zgłoszenie').should('not.exist')

    cy.visit('/')
    cy.contains('Menu').click()
    cy.contains('Moje zgłoszenia').click()
    cy.contains('Mazurska').click()
    cy.contains('Wysłano do 👮‍♀️ KP Szczecin Niebuszewo')
  })
})
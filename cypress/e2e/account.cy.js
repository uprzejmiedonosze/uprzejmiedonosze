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
    cy.wait(500)
    cy.get('.pac-container .pac-item', { timeout: 5000 }).first().click()
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
    cy.contains('ręcznie')
    cy.contains('na komendę')

    cy.get('#exposeData-N').should('be.checked')
    cy.get('#stopAgresji-SM').should('be.checked')
    cy.get('#autoSend-Y').should('be.checked')
  })

  it('New app, default settings', function () {
    cy.visit('/')
    cy.contains('Menu').click()
    cy.contains('Nowe zgłoszenie').click()
    cy.uploadOKImages()
    cy.get('#form-submit').click()

    cy.contains('Równocześnie proszę o niezamieszczanie w protokole danych dotyczących mojego miejsca zamieszkania, nr. telefonu i adresu e-mail.')
    cy.contains('zostanie za chwilę wysłane')
    cy.contains(this.sm.Szczecin.address[0])
    cy.contains('Wyślij teraz').click()

    cy.contains('Uwagi do współpracy')
    cy.contains('To twoje pierwsze zgłoszenie')

    cy.visit('/')
    cy.contains('Menu').click()
    cy.contains('Moje zgłoszenia').click()
    cy.contains('UD/').click()
    cy.contains('Wyślij do ')

    cy.visit('/')
    cy.contains('Menu').click()
    cy.contains('Do wysłania').click()
    cy.contains('UD/').click()
    cy.contains('Wyślij do ')
  })

  it('Set opposite settings', function () {
    cy.visit('/')
    cy.contains('Menu').click()
    cy.contains('Edycja konta').click({force: true})

    cy.get('#exposeData-Y').check({force: true})
    cy.get('#stopAgresji-SA').check({force: true})
    cy.get('#autoSend-N').check({force: true})

    cy.contains('Potwierdź').click()

    cy.visit('/')
    cy.contains('Menu').click()
    cy.contains('Edycja konta').click({force: true})

    cy.get('#exposeData-Y').should('be.checked')
    cy.get('#stopAgresji-SA').should('be.checked')
    cy.get('#autoSend-N').should('be.checked')
  })

  it('New app, opposite settings', function () {
    cy.visit('/')
    cy.contains('Menu').click()
    cy.contains('Nowe zgłoszenie').click()
    cy.uploadOKImages()
    cy.get('#form-submit').click()

    cy.contains('Równocześnie proszę o niezamieszczanie w protokole danych dotyczących mojego miejsca zamieszkania, nr. telefonu i adresu e-mail.').should('not.exist')
    cy.contains('zostanie za chwilę wysłane').should('not.exist')
    cy.contains('Na tym etapie nic nie zostanie wysłane do')
    cy.contains(this.sm.Szczecin.address[0]).should('not.exist')
    cy.contains('Komisariat Policji Szczecin Niebuszewo')
    cy.contains('Wyślij teraz').should('not.exist')
    cy.contains('Zapisz').click()

    cy.contains('Uwagi do współpracy z Komisariat Policji Szczecin Niebuszewo')
    cy.contains('twoje zgłoszenie zostało zapisane')
    cy.contains('To twoje pierwsze zgłoszenie').should('not.exist')

    cy.visit('/')
    cy.contains('Menu').click()
    cy.contains('Moje zgłoszenia').click()
    cy.contains('UD/').click()
    cy.contains('Wyślij zgłoszenie')

    cy.visit('/')
    cy.contains('Menu').click()
    cy.contains('Do wysłania').click()
    cy.contains('UD/').click()
    cy.contains('Wyślij do ').should('not.exist')
    cy.contains('Wyślij zgłoszenie').should('not.exist')
  })
})
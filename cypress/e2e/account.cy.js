describe('Create account', () => {
  before(() => {
    // @ts-ignore
    cy.cleanDB()
    // @ts-ignore
    cy.login()
  })

  beforeEach(() => {
    // @ts-ignore
    cy.loadConfig()
  })

  it('Check if account is not active before registration', function () {
    // Niezarejestrowany użytkownik: ekran rejestracji (layout focus, bez sidebara
    // i menu), więc do ekranów /app wchodzimy bezpośrednio po adresie.
    cy.visit('/app/new')
    cy.contains('Dane konta')
    cy.contains('Chcę pozwalać na prezentowanie').should('exist')

    cy.visit('/app/list')
    cy.contains('Dane konta')
  })

  it('Check registration form validation', function () {
    cy.visit('/app/new')
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
    cy.visit('/app/new')
    cy.contains('Dane konta')

    cy.get('#name').clear().type(this.config.user.name)
    cy.get('#address').clear().type(this.config.user.address)
    cy.contains('Potwierdź').click()
    cy.contains('Regulamin')
    cy.contains('Uprzejmie Donoszę pozwala na informowanie Straży Miejskiej')
  })

  it('Check terms screen', function () {
    cy.visit('/app/new')
    cy.contains('Dane konta').should('not.exist')

    cy.contains('Regulamin')

    cy.contains('Wyrażam zgodę na regulamin').click()
    cy.visit('/app/new')
    cy.contains('Regulamin').should('not.exist')
  })
})

describe('Update account', () => {
  beforeEach(() => {
    // @ts-ignore
    cy.loadConfig()
  })

  it('Check default', function () {
    cy.visit('/app/account?update')
    cy.contains('Zaktualizuj konto')
    cy.contains('Chcę pozwalać na prezentowanie')
    // SM/Policja is no longer asked at registration/account-edit time —
    // only via the ad hoc toggle on the new-application screen.
    cy.contains('Chcę wysyłać swoje zgłoszenia').should('not.exist')
  })

  it('New app, default settings', function () {
    cy.visit('/app/new')
    // @ts-ignore
    cy.uploadOKImages()
    // @ts-ignore
    cy.setAppCategory(this.categories)
    cy.get('input[data-type="geo"]', { timeout: 1000 }).should('not.have.class', 'error').should('not.have.class', 'clock')
    cy.get('#form-submit').click()

    cy.contains('zostanie za chwilę wysłane')
    cy.contains(this.sm.szczecin.address[0])
    // @ts-ignore
    cy.sendApp()

    cy.contains('To twoje pierwsze zgłoszenie')

    cy.visit('/app/list')
    cy.get('.application-short h3').first().click({force: true})
  })

  it('Set opposite settings', function () {
    cy.visit('/app/new')
    // @ts-ignore
    cy.uploadOKImages()
    // @ts-ignore
    cy.setAppCategory(this.categories)
    cy.get('input[data-type="geo"]', { timeout: 1000 }).should('not.have.class', 'error').should('not.have.class', 'clock')

    cy.get('#unit-sm').should('be.checked')
    cy.get('#unit-sa').check({force: true})

    cy.get('#form-submit').click()

    cy.contains('Równocześnie proszę o niezamieszczanie w protokole danych dotyczących mojego miejsca zamieszkania, nr. telefonu i adresu e-mail.').should('not.exist')
    cy.contains('KP Szczecin Niebuszewo')
    // @ts-ignore
    cy.sendApp()

    cy.contains('To twoje pierwsze zgłoszenie').should('not.exist')
  })

  it('New app, opposite settings (preference persisted)', function () {
    cy.visit('/app/new')
    // @ts-ignore
    cy.uploadOKImages()
    Object.entries(this.categories)
      .filter(c => c[1].title)
      .forEach((category) => cy.get(`input#${category[0]}`).should('be.enabled'))
    // @ts-ignore
    cy.setAppCategory(this.categories)
    cy.get('input[data-type="geo"]', { timeout: 1000 }).should('not.have.class', 'error').should('not.have.class', 'clock')

    // the choice made in the previous test was persisted to the account
    cy.get('#unit-sa').should('be.checked')

    cy.get('#form-submit').click()

    cy.contains('Równocześnie proszę o niezamieszczanie w protokole danych dotyczących mojego miejsca zamieszkania, nr. telefonu i adresu e-mail.').should('not.exist')
    cy.contains('KP Szczecin Niebuszewo')
    // @ts-ignore
    cy.sendApp()

    cy.contains('To twoje pierwsze zgłoszenie').should('not.exist')

    cy.visit('/app/list')
    cy.get('.application-short h3').first().click({force: true})
  })
})

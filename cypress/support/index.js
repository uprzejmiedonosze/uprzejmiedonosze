import './commands'

before(() => {
  cy.initDB()
})

beforeEach(() => {
  Cypress.Cookies.defaults({
    preserve: (_cookie) => true
  })
})

afterEach(function() {
  if (this.currentTest.state === 'failed') {
    Cypress.runner.stop()
  }
})

import './commands'

before(() => {
    cy.initDB()
})

beforeEach(() => {
    Cypress.Cookies.defaults({
        preserve: (cookie) => {
            return true;
        }
    })
});

afterEach(function() {
    if (this.currentTest.state === 'failed') {
        Cypress.runner.stop()
    }
});
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
import './commands'

before(() => {
    cy.initDB()
})

beforeEach(() => {
    Cypress.Cookies.defaults({
        whitelist: (cookie) => {
            return true;
        }
    })
});
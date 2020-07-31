import './commands'

before(() => {
    cy.exec('ssh nieradka.net "cd /var/www/staging.uprzejmiedonosze.net/db && cp store.sqlite-registered store.sqlite"')
})




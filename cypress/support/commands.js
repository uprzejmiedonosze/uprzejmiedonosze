Cypress.Commands.add("login", () => {
  cy.session('user', () => {
    cy.setCookie('PHPSESSID', 'i2hlj84rsvd3eglgoo61vmlhppgf4ipv')
  }, {
    cacheAcrossSpecs: true
  })
  // cy.visit('/')
})


Cypress.Commands.add("uploadFile", (selector, fileUrl, type = "") => cy.get(selector)
  .then((subject) => cy.fixture(fileUrl, "base64")
    .then(Cypress.Blob.base64StringToBlob)
      .then((blob) => cy.window()
        .then((win) => {
          const el = subject[0];
          const nameSegments = fileUrl.split("/");
          const name = nameSegments[nameSegments.length - 1];
          const testFile = new win.File([blob], name, { type });
          const dataTransfer = new DataTransfer();
          dataTransfer.items.add(testFile);
          el.files = dataTransfer.files;
          return cy.wrap(subject).trigger('change', { force: true });
      })
    )
  )
)

Cypress.Commands.add("uploadOKImages", () => {
  cy.uploadFile('input[type=file]#contextImage', 'img_c.jpg',
  'image/jpeg')
  cy.uploadFile('input[type=file]#carImage', 'img_p.jpg',
    'image/jpeg')

  cy.get('.carImageSection img', { timeout: 12000 }).should('have.attr', 'src').should('include', 'cdn')
});

Cypress.Commands.add("uploadWrongImages", () => {
  cy.uploadFile('input[type=file]#contextImage', 'img_e.jpg',
    'image/jpeg')
  cy.uploadFile('input[type=file]#carImage', 'img_e.jpg',
    'image/jpeg')

  cy.get('.carImageSection img', { timeout: 12000 }).should('have.attr', 'src').should('include', 'cdn')
});

Cypress.Commands.add("loadConfig", () => {
  cy.fixture('config.json').then(function (config) {
    this.config = config;
  })
  cy.fixture('../../src/api/config/sm.json').then(function (sm) {
    this.sm = sm;
  })
  cy.fixture('../../src/api/config/statuses.json').then(function (statuses) {
    this.statuses = statuses;
  })
  cy.fixture('../../src/api/config/categories.json').then(function (categories) {
    this.categories = categories;
  })
  cy.fixture('../../src/api/config/extensions.json').then(function (extensions) {
    this.extensions = extensions;
  })
});

Cypress.Commands.add("initDB", () => {
  if (Cypress.env('DOCKER')) {
    cy.exec('docker exec webapp cp /var/www/uprzejmiedonosze.localhost/db/store-registered-empty.sqlite /var/www/uprzejmiedonosze.localhost/db/store.sqlite')
    return
  }
  cy.exec('ssh nieradka.net "cd /var/www/staging.uprzejmiedonosze.net/db && cp store.sqlite-registered store.sqlite"')
})

Cypress.Commands.add("cleanDB", () => {
  if (Cypress.env('DOCKER')) {
    cy.exec('docker exec webapp cp /var/www/uprzejmiedonosze.localhost/db/store.sqlite-empty /var/www/uprzejmiedonosze.localhost/db/store.sqlite')
    return
  }
  cy.exec('ssh nieradka.net "cd /var/www/staging.uprzejmiedonosze.net/db && cp store.sqlite-empty store.sqlite"')
})

Cypress.Commands.add("goToNewAppScreen", () => {
  cy.goToNewAppScreenWithoutTermsScreen()
  cy.contains('Pełen regulamin oraz polityka prywatności Uprzejmie Donoszę')
  cy.contains('Dalej').click()
})

Cypress.Commands.add("goToNewAppScreenWithoutTermsScreen", () => {
  cy.visit('/')
  cy.contains('Menu').click()
  cy.contains('Nowe zgłoszenie').click({force: true})
})

Cypress.Commands.add("login", () => {
  cy.session('user' + Date.now(), () => {
    cy.setCookie('PHPSESSID', 'og2iqbv5httioomnjl8ckmf1db16jmm2')
  }, {
    cacheAcrossSpecs: true
  })
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

Cypress.Commands.add("uploadOKImages", (carImage='img_p.jpg') => {
  cy.uploadFile('input[type=file]#contextImage', 'img_c.jpg',
  'image/jpeg')
  cy.uploadFile('input[type=file]#carImage', carImage,
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

Cypress.Commands.add("setAppCategory", (categories) => {
  const firstNonDefaultCategoryId = Object.entries(categories).filter(c => c[1].law)[0][0]
  cy.get(`input#${firstNonDefaultCategoryId}`).click({force: true})
})

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
  cy.fixture('../../src/api/config/badges.json').then(function (badges) {
    this.badges = badges;
  })
  cy.fixture('../../src/api/config/levels.json').then(function (levels) {
    this.levels = levels;
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

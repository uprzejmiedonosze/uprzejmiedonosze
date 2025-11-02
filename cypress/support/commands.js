// @ts-nocheck
Cypress.Commands.add("login", () => {
  cy.session('user' + Date.now(), () => {
    cy.setCookie('PHPSESSID', '48msfr815nd7f6ujomebqdpil9jueuq0') // dev -> docker
    cy.setCookie('UDSESSIONID', '5rfiiffhr6gmqrcovk2bnte5opmqa2hgfiht7kt7') // staging
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

Cypress.Commands.add("sendApp", () => {
  cy.intercept('PATCH', '/api/app/**').as("send")
  cy.contains('Wyślij do').click()
  cy.wait("@send")
  cy.get('.afterSend', { timeout: 30000 }).should('be.visible')
})

Cypress.Commands.add("uploadOKImages", (carImage='img_p.jpg') => {
  cy.uploadFile('input[type=file]#contextImage', 'img_c.jpg', 'image/jpeg')

  cy.intercept('POST', '/api/app/**/image').as("image")
  cy.intercept('GET', '/api/geo/**/m').as("mapbox")
  cy.intercept('GET', '/api/geo/**/n').as("nominantim")

  cy.uploadFile('input[type=file]#carImage', carImage,
    'image/jpeg')

  cy.wait('@image')
  cy.wait('@mapbox')
  cy.wait('@nominantim')
  cy.get('#plateImage').should('be.visible')
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
  cy.fixture('../../export/public/api/config/sm.json').then(function (sm) {
    this.sm = sm;
  })
  cy.fixture('../../export/public/api/config/statuses.json').then(function (statuses) {
    this.statuses = statuses;
  })
  cy.fixture('../../export/public/api/config/categories.json').then(function (categories) {
    this.categories = categories;
  })
  cy.fixture('../../export/public/api/config/extensions.json').then(function (extensions) {
    this.extensions = extensions;
  })
  cy.fixture('../../export/public/api/config/badges.json').then(function (badges) {
    this.badges = badges;
  })
  cy.fixture('../../export/public/api/config/levels.json').then(function (levels) {
    this.levels = levels;
  })
});

Cypress.Commands.add("initDB", () => {
  if (Cypress.env('DOCKER'))
    return cy.exec('docker exec webapp sqlite3 /var/www/localhost/db/store.sqlite -init /var/www/localhost/webapp/sql/init_registered.sql')

  cy.exec('ssh nieradka.net "sqlite3 /var/www/staging.uprzejmiedonosze.net/db/store.sqlite < /var/www/staging.uprzejmiedonosze.net/webapp/sql/init_registered.sql"')
})

Cypress.Commands.add("cleanDB", () => {
  if (Cypress.env('DOCKER'))
    return cy.exec('docker exec webapp sqlite3 /var/www/localhost/db/store.sqlite -init /var/www/localhost/webapp/sql/init_empty.sql')

  cy.exec('ssh nieradka.net "sqlite3 /var/www/staging.uprzejmiedonosze.net/db/store.sqlite < /var/www/staging.uprzejmiedonosze.net/webapp/sql/init_empty.sql"')
})

Cypress.Commands.add("goToNewAppScreen", () => {
  cy.goToNewAppScreenWithoutTermsScreen()
  cy.contains('Pełen regulamin oraz polityka prywatności Uprzejmie Donoszę')
  cy.contains('Wyrażam zgodę na regulamin').click()
})

Cypress.Commands.add("goToNewAppScreenWithoutTermsScreen", () => {
  cy.visit('/')
  cy.get('label.menu > .button-toggle').click()
  cy.contains('Nowe zgłoszenie').click({force: true})
})

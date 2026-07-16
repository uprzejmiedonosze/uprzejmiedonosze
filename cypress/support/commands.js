// @ts-nocheck
Cypress.Commands.add("login", () => {
  cy.session('user' + Date.now(), () => {
    cy.setCookie('PHPSESSID', '48msfr815nd7f6ujomebqdpil9jueuq0') // dev -> docker
    cy.setCookie('UDSESSIONID', '4ql346r0u72e66jml6dq72bo0fofk40n30cfc8lh') // staging
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
  cy.url().should('include', 'app/done')
})

Cypress.Commands.add("uploadOKImages", (carImage = 'img_p.jpg') => {
  cy.intercept('POST', '/api/app/**/image').as("image")
  cy.intercept('GET', '/api/geo/**/m').as("mapbox")
  cy.intercept('GET', '/api/geo/**/n').as("nominantim")

  // Czekamy aż zdjęcie kontekstowe faktycznie się zapisze (src → cdn) zanim
  // wgramy kolejne. Bez tego zgłoszenie mogło trafić do potwierdzenia bez
  // zdjęcia kontekstowego — w edycji formularz pokazywał wtedy placeholder,
  // a walidacja (checkImages) słusznie blokowała „Dalej".
  cy.uploadFile('input[type=file]#contextImage', 'img_c.jpg', 'image/jpeg')
  cy.wait('@image')
  cy.get('.contextImageSection img', { timeout: 12000 }).should('have.attr', 'src').should('include', 'cdn')

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
  cy.get(`input#${firstNonDefaultCategoryId}`).click({ force: true })
})

Cypress.Commands.add("loadConfig", () => {
  cy.fixture('config.json').then(function (config) {
    this.config = config;
  })
  cy.fixture('../../export/public/api/config/sm.json').then(function (sm) {
    this.sm = sm;
  })
  cy.fixture('../../export/public/api/config/police.json').then(function (police) {
    this.police = police;
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
  if (Cypress.config('DOCKER'))
    return cy.exec('docker exec webapp sqlite3 /var/www/uprzejmiedonosze.net/db/store.sqlite -init /var/www/uprzejmiedonosze.net/webapp/sql/init_registered.sql')

  cy.exec('ssh nieradka.net "sqlite3 /var/www/staging.uprzejmiedonosze.net/db/store.sqlite < /var/www/staging.uprzejmiedonosze.net/webapp/sql/init_registered.sql"')
})

Cypress.Commands.add("cleanDB", () => {
  if (Cypress.config('DOCKER'))
    return cy.exec('docker exec webapp sqlite3 /var/www/uprzejmiedonosze.net/db/store.sqlite -init /var/www/uprzejmiedonosze.net/webapp/sql/init_empty.sql')

  cy.exec('ssh nieradka.net "sqlite3 /var/www/staging.uprzejmiedonosze.net/db/store.sqlite < /var/www/staging.uprzejmiedonosze.net/webapp/sql/init_empty.sql"')
})

Cypress.Commands.add("goToNewAppScreen", () => {
  cy.goToNewAppScreenWithoutTermsScreen()
  // /app/new przekierowuje na /app/start (ekran zgody) tylko, gdy regulamin nie
  // jest jeszcze zaakceptowany — na staging zależy to od stanu DB/cache. Helper
  // ma jedynie dojść do formularza, więc zgodę klikamy warunkowo (osobny test
  // samego ekranu zgody żyje w account.cy.js).
  cy.location('pathname').then((path) => {
    if (path.includes('/app/start')) {
      cy.contains('Wyrażam zgodę na regulamin').click()
    }
  })
})

Cypress.Commands.add("goToNewAppScreenWithoutTermsScreen", () => {
  cy.appMenu('Nowe zgłoszenie')
})

// Public-site navigation. Po redesignie hamburger menu jest ukryte na desktopie
// (Cypress renderuje w 1000px, powyżej breakpointu 768px), więc pozycje menu
// przenieśliśmy do stopki — obecnej na każdej stronie static/main. Klikamy link
// w <footer> (usuwając target, by zewnętrzne linki nie otwierały nowej karty).
Cypress.Commands.add("footerLink", (label) => {
  cy.get('footer').contains('a', label).invoke('removeAttr', 'target').click()
})

// /app navigation. Sekcja /app ma własny sidebar (.mpr-sidebar--app) widoczny na
// desktopie na każdej stronie /app/* — to on zastępuje dawne menu hamburgera dla
// zalogowanego, zarejestrowanego użytkownika. Wchodzimy na dashboard i klikamy
// pozycję w tym sidebarze.
Cypress.Commands.add("appMenu", (label) => {
  cy.visit('/app')
  cy.get('.mpr-sidebar--app').contains('a', label).click({ force: true })
})

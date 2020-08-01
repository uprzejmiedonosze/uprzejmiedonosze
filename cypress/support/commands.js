Cypress.Commands.add("login", () => {
    cy.setCookie('PHPSESSID', '0je0ftva9fcjjttsqb1tmjoq3t6tskcg')
});

Cypress.Commands.add("uploadFile", (selector, fileUrl, type = "") => {
    return cy.get(selector).then(subject => {
        return cy
            .fixture(fileUrl, "base64")
            .then(Cypress.Blob.base64StringToBlob)
            .then(blob => {
                return cy.window().then(win => {
                    const el = subject[0];
                    const nameSegments = fileUrl.split("/");
                    const name = nameSegments[nameSegments.length - 1];
                    const testFile = new win.File([blob], name, { type });
                    const dataTransfer = new DataTransfer();
                    dataTransfer.items.add(testFile);
                    el.files = dataTransfer.files;
                    return cy.wrap(subject).trigger('change', { force: true });
                });
            });
    });
});

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
});

Cypress.Commands.add("preserveLoginCookie", () => {
    Cypress.Cookies.preserveOnce('PHPSESSID')
});

Cypress.Commands.add("initDB", () => {
    cy.exec('ssh nieradka.net "cd /var/www/staging.uprzejmiedonosze.net/db && cp store.sqlite-registered store.sqlite"')
})

Cypress.Commands.add("goToNewAppScreen", () => {
    cy.visit('/')
    cy.contains('Menu').click()
    cy.contains('Nowe zgłoszenie').click()
    cy.contains('Pełen regulamin oraz polityka prywatności Uprzejmie Donoszę')
    cy.contains('Dalej').click()
})
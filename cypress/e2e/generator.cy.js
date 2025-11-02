describe('Generator pism - strona główna', () => {
    before(() => {
        // @ts-ignore
        cy.initDB()
        // @ts-ignore
        cy.login()
    })

    it('sprawdza dostęp do kreatora pism z menu', () => {
        cy.visit('/')
        cy.get('label.menu > .button-toggle').click()
        cy.contains('Napisz pismo do polityka').invoke('removeAttr', 'target').click()
        cy.location('pathname').should('include', '/napisz-pismo-do-polityka.html')
    })

    it('sprawdza stronę główną kreatora', () => {
        cy.visit('/napisz-pismo-do-polityka.html')
        cy.contains('Kreator pism do polityków')
        cy.contains('Przekształć frustrację parkingową w realne działanie')
        cy.contains('Tylko 3 minuty')
        cy.contains('100% Unikalne Pisma')
        cy.contains('Zacznij Działać Teraz!')
        cy.contains('Stwórz pismo!').should('have.attr', 'href', '/generator.html')
    })

    it('przechodzi do kreatora', () => {
        cy.visit('/napisz-pismo-do-polityka.html')
        cy.contains('Stwórz pismo!').click()
        cy.location('pathname').should('include', '/generator.html')
    })
})

describe('Generator pism - walidacja kroków', () => {
    before(() => {
        // @ts-ignore
        cy.login()
    })

    beforeEach(() => {
        cy.visit('/generator.html')
        cy.wait(1000) // czekamy na załadowanie danych
    })

    it('sprawdza krok 1 - wybór tematów', () => {
        cy.contains('Krok 1 z')
        cy.get('#fs-topics').should('be.visible')
        cy.get('#fs-topics input[type="checkbox"]').should('have.length.greaterThan', 0)

        // Sprawdza walidację - brak wyboru
        cy.get('#step-1 a.button').should('contain', 'Wybierz do 3 opcji').and('have.class', 'disabled')

        // Wybiera jeden temat
        cy.get('#fs-topics label').first().click()
        cy.get('#step-1 a.button').should('contain', 'Dalej').and('not.have.class', 'disabled')

        // Sprawdza walidację - za dużo opcji
        cy.get('#fs-topics label').click({ multiple: true })
        cy.get('#step-1 a.button').should('contain', 'Wybierz do 3 opcji').and('have.class', 'disabled')
    })

    it('sprawdza krok 2 - wybór tonu pisma', () => {
        // Przechodzi do kroku 2
        cy.get('#fs-topics label').first().click()
        cy.get('#step-1 a.button').click()

        cy.contains('Krok 2 z')
        cy.get('#fs-types').should('be.visible')
        cy.get('#fs-types input[type="radio"]').should('have.length.greaterThan', 0)

        // Wybiera ton pisma
        cy.get('#fs-types label').first().click()
        cy.get('#step-2 a.button').should('contain', 'Dalej').and('not.have.class', 'disabled')
    })

    it('sprawdza krok 3 - wybór adresata', () => {
        // Przechodzi do kroku 3
        cy.get('#fs-topics label').first().click()
        cy.get('#step-1 a.button').click()
        cy.get('#fs-types label').first().click()
        cy.get('#step-2 a.button').click()

        cy.contains('Krok 3 z')
        cy.get('#fs-targets').should('be.visible')
        cy.get('#fs-targets input[type="radio"]').should('have.length.greaterThan', 0)

        // Sprawdza walidację - brak wyboru
        cy.get('#step-3 a.button').should('contain', 'Dalej').and('have.class', 'disabled')

        // Wybiera adresata
        cy.get('#fs-targets label').first().click()
        cy.get('#step-3 a.button').should('not.have.class', 'disabled')
    })

    it('sprawdza nawigację wstecz', () => {
        // Przechodzi do kroku 2
        cy.get('#fs-topics label').first().click()
        cy.get('#step-1 a.button').click()

        // Wraca do kroku 1
        cy.get('a[data-action="prev"]').click()
        cy.contains('Krok 1 z')

        // Wraca do strony głównej
        cy.get('a[data-action="prev"]').click()
        cy.location('pathname').should('include', '/napisz-pismo-do-polityka.html')
    })
})

describe('Generator pism - pełny przepływ', () => {
    before(() => {
        // @ts-ignore
        cy.login()
    })

    beforeEach(() => {
        cy.visit('/generator.html')
        cy.wait(1000) // czekamy na załadowanie danych
    })


})

describe('Generator pism - obsługa błędów', () => {
    before(() => {
        // @ts-ignore
        cy.login()
    })

    it('sprawdza obsługę błędów ładowania danych', () => {
        // Przechwytuje żądania API i zwraca błędy
        cy.intercept('GET', '/generator/topics', { statusCode: 500 }).as('topicsError')

        cy.visit('/generator.html')
        cy.wait('@topicsError')

        // Sprawdza czy wyświetla się komunikat o błędzie
        cy.contains('Wystąpił błąd podczas ładowania danych')
    })

    it('sprawdza obsługę błędów generowania', () => {
        cy.visit('/generator.html')
        cy.wait(1000)

        // Przechodzi przez kroki
        cy.get('#fs-topics label').first().click()
        cy.get('#step-1 a.button').click()
        cy.get('#fs-types label').first().click()
        cy.get('#step-2 a.button').click()
        cy.get('#fs-targets label').first().click()

        // Przechwytuje żądanie generowania i zwraca błąd
        cy.intercept('GET', '/generator/stream*', { statusCode: 500, body: '{"error": "Test error"}' }).as('generateError')

        cy.get('#step-3 a.button').click()

        // Sprawdza czy wyświetla się komunikat o błędzie
        cy.contains('Błąd: Test error', { timeout: 10000 })
    })
})

describe('Generator pism - różne typy adresatów', () => {
    before(() => {
        // @ts-ignore
        cy.login()
    })

    beforeEach(() => {
        cy.visit('/generator.html')
        cy.wait(1000)
    })

    it('sprawdza adresata z bezpośrednim emailem', () => {
        cy.get('#fs-topics label').first().click()
        cy.get('#step-1 a.button').click()
        cy.get('#fs-types label').first().click()
        cy.get('#step-2 a.button').click()

        // Szuka adresata z bezpośrednim emailem (nie selector)
        cy.get('#fs-targets input[type="radio"]').each(($radio) => {
            const recipient = $radio.attr('data-recipient')
            if (recipient && recipient.includes('@') && !recipient.startsWith('selector:')) {
                cy.wrap($radio).parent('label').click()
                return false // przerywa pętlę
            }
        })

        cy.get('#step-3 a.button').should('contain', 'Dalej')
    })

    it('sprawdza adresata z selektorem', () => {
        cy.get('#fs-topics label').first().click()
        cy.get('#step-1 a.button').click()
        cy.get('#fs-types label').first().click()
        cy.get('#step-2 a.button').click()

        // Szuka adresata z selektorem
        cy.get('#fs-targets input[type="radio"]').each(($radio) => {
            const recipient = $radio.attr('data-recipient')
            if (recipient && recipient.startsWith('selector:')) {
                cy.wrap($radio).parent('label').click()
                return false // przerywa pętlę
            }
        })

        cy.get('#step-3 a.button').should('contain', 'Dalej')
        cy.get('#step-3 a.button').click()

        // Sprawdza czy pojawił się krok 4
        cy.contains('Krok 4 z')
        cy.wait(2000) // czekamy na załadowanie odbiorców
        cy.get('#step-4 fieldset').should('not.be.empty')
    })

    it('sprawdza Rzecznika Praw Obywatelskich - formularz', () => {
        // Przechodzi przez pierwsze kroki
        cy.get('#fs-topics label').first().click()
        cy.get('#step-1 a.button').click()
        cy.get('#fs-types label').first().click()
        cy.get('#step-2 a.button').click()

        // Wybiera Rzecznika Praw Obywatelskich
        cy.contains('Rzecznika Praw Obywatelskich').click()
        cy.get('#step-3 a.button').click()

        // Generowanie pisma
        cy.contains('Tworzenie pisma', { timeout: 10000 })
        cy.get('#output', { timeout: 60000 }).should('not.be.empty')
        cy.contains('Gotowe do wysłania', { timeout: 60000 })

        // Sprawdza czy jest przycisk "Otwórz formularz"
        cy.get('#openFormButton').should('be.visible')
        cy.get('#mailtoButton').should('not.be.visible')
        cy.get('#gmailtoButton').should('not.be.visible')
    })

    it('sprawdza Członka Sejmowej Komisji Infrastruktury - Paulina Matysiak', () => {
        // Przechodzi przez pierwsze kroki
        cy.get('#fs-topics label').first().click()
        cy.get('#step-1 a.button').click()
        cy.get('#fs-types label').first().click()
        cy.get('#step-2 a.button').click()

        // Wybiera Członka/ini Sejmowej Komisji Infrastruktury
        cy.contains('Członka/ini Sejmowej Komisji Infrastruktury').click()
        cy.get('#step-3 a.button').click()

        // Sprawdza czy jest krok 4 z 4
        cy.contains('Krok 4 z 4')
        cy.wait(2000) // czekamy na załadowanie odbiorców

        // Sprawdza czy na liście jest Paulina Matysiak i wybiera ją
        cy.contains('Paulina Matysiak').should('be.visible').click()
        cy.get('#step-4 a.button').click()

        // Generowanie pisma
        cy.contains('Tworzenie pisma', { timeout: 10000 })
        cy.get('#output', { timeout: 60000 }).should('not.be.empty')
        cy.contains('Gotowe do wysłania', { timeout: 60000 })

        // Sprawdza czy jest przycisk "Wyślij przez Gmaila" z klasą "cta"
        cy.get('#gmailtoButton').should('be.visible').and('have.class', 'cta')
        cy.get('#mailtoButton').should('be.visible')
        cy.get('#openFormButton').should('not.be.visible')
    })
})
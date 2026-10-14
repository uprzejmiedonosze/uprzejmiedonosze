---
name: cypress
description: Run Cypress e2e tests for this project. Use when the user asks to run Cypress tests, run a spec file, or test a specific flow end-to-end.
---

# Cypress

Run e2e tests via:

```bash
npx cypress run --e2e --runner-ui --spec <FILE>
```

## Spec files

| Plik | Co testuje |
|---|---|
| `cypress/e2e/staticPages.cy.js` | Statyczne strony (homepage, regulamin, itp.) |
| `cypress/e2e/account.cy.js` | Rejestracja, logowanie, konto użytkownika |
| `cypress/e2e/generator.cy.js` | Generator pism |
| `cypress/e2e/myApps.cy.js` | Lista zgłoszeń użytkownika |
| `cypress/e2e/SMVariants.cy.js` | Warianty straży miejskich |

## Examples

```bash
# Jeden plik
npx cypress run --e2e --runner-ui --spec cypress/e2e/staticPages.cy.js

# Wszystkie testy
npx cypress run --e2e --runner-ui
```

## Notes

- `--runner-ui` otwiera interfejs graficzny przeglądarki — wymagane do obserwacji testu
- Testy działają pod adresem zdefiniowanym w `cypress.config.js` (`baseUrl`)
- Jeśli test wymaga środowiska Docker: `CYPRESS_DOCKER=1 npx cypress run --e2e --runner-ui --spec <FILE>`
- W razie błędu bazy danych w teście: `cy.initDB()` / `cy.cleanDB()` resetują SQLite przez `docker exec webapp`

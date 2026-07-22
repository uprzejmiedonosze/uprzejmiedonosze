const { defineConfig } = require("cypress");

const cypressFailFast = require('cypress-fail-fast/plugin');

module.exports = defineConfig({
  projectId: "ttzx1f",
  allowCypressEnv: false,
  DOCKER: process.env.CYPRESS_DOCKER === 'true' || process.env.CYPRESS_DOCKER === '1',

  // This config file lives in devtools/ (a separate npm project, kept out of
  // the Docker builder image), while the actual specs/fixtures stay in the
  // top-level cypress/ folder — hence the ../cypress paths below.
  fixturesFolder: "../cypress/fixtures",
  screenshotsFolder: "../cypress/screenshots",
  videosFolder: "../cypress/videos",
  downloadsFolder: "../cypress/downloads",

  e2e: {
    setupNodeEvents(on, config) {
      cypressFailFast(on, config);
    },

    specPattern: "../cypress/e2e/**/*.cy.js",
    supportFile: "../cypress/support/e2e.js",
    experimentalRunAllSpecs: true,
    watchForFileChanges: false,
    viewportHeight: 1000,
    baseUrl: "https://staging.uprzejmiedonosze.net",
    testIsolation: false
  },
  retries: {
    runMode: 1,
    openMode: 1,
  },
});

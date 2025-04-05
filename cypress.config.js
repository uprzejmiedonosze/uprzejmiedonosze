const { defineConfig } = require("cypress");

const cypressFailFast = require('cypress-fail-fast/plugin');

module.exports = defineConfig({
  projectId: "ttzx1f",

  e2e: {
    setupNodeEvents(on, config) {
      cypressFailFast(on, config);
    },

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

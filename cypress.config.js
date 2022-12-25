const { defineConfig } = require("cypress");

module.exports = defineConfig({
  projectId: "ttzx1f",

  e2e: {
    setupNodeEvents(on, config) {
      // implement node event listeners here
    },
    baseUrl: "https://staging.uprzejmiedonosze.net",
    testIsolation: false
  },
});

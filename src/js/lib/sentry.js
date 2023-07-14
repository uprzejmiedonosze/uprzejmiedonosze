import * as Sentry from "@sentry/browser"

Sentry.init({
  dsn: "https://66b5f54d37b1474daf0d95059e69c0b2@o929176.ingest.sentry.io/5878019",
  tracesSampleRate: 0.3,
  environment: window.location.hostname,
  denyUrls: [
    // Google Adsense
    /pagead\/js/i,
    // Facebook flakiness
    /graph\.facebook\.com/i,
    // Facebook blocked
    /connect\.facebook\.net\/en_US\/all\.js/i,
    // Woopra flakiness
    /eatdifferent\.com\.woopra-ns\.com/i,
    /static\.woopra\.com\/js\/woopra\.js/i,
    // Chrome extensions
    /extensions\//i,
    /^chrome:\/\//i,
    // Other plugins
    /127\.0\.0\.1:4001\/isrunning/i,  // Cacaoweb
    /webappstoolbarba\.texthelp\.com\//i,
    /metrics\.itunes\.apple\.com\.edgesuite\.net\//i
  ]
});

Sentry.setTag("environment", window.location.hostname);

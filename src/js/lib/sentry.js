import * as Sentry from "@sentry/browser"

if (!window.location.hostname.includes('staging')) {
  Sentry.init({
    dsn: "https://66b5f54d37b1474daf0d95059e69c0b2@o929176.ingest.sentry.io/5878019",
    tracesSampleRate: 0.1,
    integrations: [
      new Sentry.Replay()
    ],
    replaysSessionSampleRate: 0.1,
    replaysOnErrorSampleRate: 1.0,
    environment: window.location.hostname,
    denyUrls: [
      /pagead\/js/i, // Google Adsense
      /graph\.facebook\.com/i, // Facebook flakiness
      /connect\.facebook\.net\/en_US\/all\.js/i, // Facebook blocked
      /eatdifferent\.com\.woopra-ns\.com/i, // Woopra flakiness
      /static\.woopra\.com\/js\/woopra\.js/i, // Woopra flakiness
      /extensions\//i, // Chrome extensions
      /^chrome:\/\//i,
      /127\.0\.0\.1:4001\/isrunning/i,  // Cacaoweb
      /webappstoolbarba\.texthelp\.com\//i,
      /metrics\.itunes\.apple\.com\.edgesuite\.net\//i
    ]
  });

  Sentry.setTag("environment", window.location.hostname);
  const currentScript = document.currentScript
  const userNumber = currentScript?.getAttribute("user-number") ?? 0
  Sentry.setTag("userNumber", userNumber)

}

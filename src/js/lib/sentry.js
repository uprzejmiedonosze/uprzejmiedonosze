import * as Sentry from "@sentry/browser"
// import { BrowserTracing } from "@sentry/tracing"

Sentry.init({
  dsn: "https://66b5f54d37b1474daf0d95059e69c0b2@o929176.ingest.sentry.io/5878019",
  // integrations: [new BrowserTracing()],
  tracesSampleRate: 0.3,
  environment: window.location.hostname
});

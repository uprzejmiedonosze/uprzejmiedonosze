// Stylelint pilnuje konwencji z src/scss/AGENTS.md (tokeny zamiast wartości na sztywno),
// nie stylu formatowania. Uruchamiaj z katalogu repo: `make lint-css`.
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const abs = (paths) => paths.map((p) => resolve(root, p));

// Pliki z naruszeniami sprzed wprowadzenia lintera (2026-10-14). Dla nich reguły są
// ostrzeżeniami, nie błędami. Gdy plik zostanie zmigrowany na tokeny, usuń go z listy.
// Nie dopisuj tu nowych plików.
const LEGACY = [
  "src/scss/general.scss",
  "src/scss/index3.scss",
  "src/scss/lib/button.scss",
  "src/scss/lib/dialog.scss",
  "src/scss/lib/dropdown.scss",
  "src/scss/lib/layout.scss",
  "src/scss/lib/menu.scss",
  "src/scss/lib/radio.scss",
  "src/scss/lib/recydywa.scss",
  "src/scss/lib/statuses.scss",
  "src/scss/lib/tabs.scss",
  "src/scss/lib/toast.scss",
  "src/scss/lib/variables.scss",
  "src/scss/lib/warning-header.scss",
  "src/scss/sites/application.scss",
  "src/scss/sites/ask-for-status.scss",
  "src/scss/sites/car-stats.scss",
  "src/scss/sites/confirm.scss",
  "src/scss/sites/faq.scss",
  "src/scss/sites/frequent-mistakes.scss",
  "src/scss/sites/gallery.scss",
  "src/scss/sites/generator.scss",
  "src/scss/sites/how.scss",
  "src/scss/sites/main-redesign.scss",
  "src/scss/sites/main.scss",
  "src/scss/sites/mcp.scss",
  "src/scss/sites/my-apps.scss",
  "src/scss/sites/new-application.scss",
  "src/scss/sites/patronite.scss",
  "src/scss/sites/public-info.scss",
  "src/scss/sites/register.scss",
  "src/scss/sites/rules.scss",
  "src/scss/sites/thank-you.scss",
  "src/scss/sites/wniosek-rpo.scss",
  "src/scss/sites/zenfeed.scss",
];

export default {
  customSyntax: "postcss-scss",
  plugins: ["stylelint-scss"],
  rules: {
    "color-no-hex": [true, { message: "Kolor na sztywno — użyj tokenu $mpr-* z lib/tokens.scss (albo dodaj tam nowy)" }],
    "color-named": ["never", { message: "Nazwany kolor — użyj tokenu $mpr-* z lib/tokens.scss" }],
    "color-no-invalid-hex": true,
    "declaration-block-no-duplicate-properties": [true, { ignore: ["consecutive-duplicates-with-different-values"] }],
    "block-no-empty": true,
    "scss/at-rule-no-unknown": true,
  },
  overrides: [
    {
      // Jedyne miejsca, gdzie definiujemy surowe kolory.
      files: abs(["src/scss/lib/tokens.scss", "src/scss/lib/variables.env.scss"]),
      rules: { "color-no-hex": null, "color-named": null },
    },
    {
      files: abs(LEGACY),
      defaultSeverity: "warning",
      rules: {
        "color-no-hex": [true, { severity: "warning" }],
        "color-named": ["never", { severity: "warning" }],
        "declaration-block-no-duplicate-properties": [true, { severity: "warning", ignore: ["consecutive-duplicates-with-different-values"] }],
      },
    },
  ],
};

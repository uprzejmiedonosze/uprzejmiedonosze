#!/usr/bin/env bash
# PostToolUse (Write|Edit): szybki lint właśnie zmienionego pliku.
# Exit 2 + stderr = Claude dostaje błędy i poprawia je od razu. Brak narzędzia = cicho pomijamy.
set -uo pipefail

root="${CLAUDE_PROJECT_DIR:-$(cd "$(dirname "$0")/../.." && pwd)}"
file="$(jq -r '.tool_response.filePath // .tool_input.file_path // empty')"
[ -n "$file" ] && [ -f "$file" ] || exit 0

case "$file" in
  "$root"/*) rel="${file#"$root"/}" ;;
  *) exit 0 ;;
esac

out=""
case "$rel" in
  src/scss/*.scss)
    bin="$root/tools/node_modules/.bin/stylelint"
    [ -x "$bin" ] || exit 0
    out="$(cd "$root" && "$bin" --config tools/stylelint.config.mjs --quiet "$rel" 2>&1)" && out="" ;;
  src/*.php|tests/*.php)
    command -v php >/dev/null || exit 0
    out="$(php -l "$file" 2>&1 | grep -v '^No syntax errors')" ;;
  src/templates/*.twig)
    bin="$root/vendor/bin/twig-linter"
    [ -x "$bin" ] && command -v php >/dev/null || exit 0
    out="$("$bin" lint --no-interaction "$file" 2>&1)" && out="" ;;
  src/api/config/*.json)
    out="$(node -e 'try { JSON.parse(require("fs").readFileSync(process.argv[1], "utf8")) } catch (e) { console.log(e.message); process.exit(1) }' "$file" 2>&1)" ;;
  *) exit 0 ;;
esac

if [ -n "$out" ]; then
  printf 'Lint: %s\n%s\n' "$rel" "$out" >&2
  exit 2
fi
exit 0

import { filterable } from '../lib/filterable'
import { message, error } from '../lib/toast'

// Wiki: przycisk „Kopiuj” we wzorach pism (:::wzor, wiki/_directive-wzor.html.twig). Delegacja, bo wzór pojawia się
// też w podglądzie edytora i w poradniku redaktora.
document.addEventListener('click', event => {
  // @ts-ignore
  const button = event.target?.closest?.('[data-wiki-copy]')
  if (!button) return
  const body = button.closest('.wiki-wzor')?.querySelector('.wiki-wzor__body')
  if (!body) return
  navigator.clipboard.writeText(body.innerText.trim())
    .then(() => message('Skopiowano wzór do schowka'))
    .catch(() => error('Nie udało się skopiować — zaznacz tekst i skopiuj ręcznie'))
})

// Lista jednostek /wiki/sluzby (wiki-units.html.twig)
filterable('wiki-units-filter', 'wiki-units-filter-list')

// Diagramy ```mermaid (renderer wiki: <pre class="mermaid">). Biblioteka z CDN tylko tam, gdzie jest diagram;
// securityLevel 'strict', bo etykiety to treść redaktorów. Ta sama wersja co wcześniej na faq.html.
let mermaidPromise = null

/** @param {ParentNode} root */
export function renderMermaid(root = document) {
  const pending = root.querySelectorAll('pre.mermaid:not([data-processed])')
  if (!pending.length) return
  mermaidPromise ??= new Promise(resolve => {
    // @ts-ignore
    window.__udMermaidReady = resolve
    const script = document.createElement('script')
    script.type = 'module'
    script.textContent = "import mermaid from 'https://cdn.jsdelivr.net/npm/mermaid@11.4.1/dist/mermaid.esm.min.mjs';"
      + "mermaid.initialize({ startOnLoad: false, securityLevel: 'strict' }); window.__udMermaidReady(mermaid)"
    document.head.appendChild(script)
  })
  // @ts-ignore
  mermaidPromise.then(mermaid => mermaid.run({ nodes: pending }))
}

document.addEventListener('DOMContentLoaded', () => renderMermaid())

// Stare kotwice DokuWiki (#pojazd_stal_za_zakazem…, linki z Google i zewnętrznych stron) → identyfikatory nagłówków
// nowej wiki (#pojazd-stal-za-zakazem…). Fragment nie trafia do serwera, więc WikiHandler::legacyRedirect go nie poprawi.
function fixLegacyAnchor() {
  let id
  try { id = decodeURIComponent(location.hash.slice(1)) } catch { return }
  if (!id.includes('_') || document.getElementById(id)) return
  const target = document.getElementById(id.replaceAll('_', '-'))
  if (!target) return
  history.replaceState(null, '', '#' + target.id)
  target.scrollIntoView()
}

document.addEventListener('DOMContentLoaded', fixLegacyAnchor)
window.addEventListener('hashchange', fixLegacyAnchor)

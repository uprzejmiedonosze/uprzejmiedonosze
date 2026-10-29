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

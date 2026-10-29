import Api from '../lib/Api'

// Edytor wiki (wiki-edit.html.twig): podgląd na żywo przez POST /wiki/_preview (ten sam renderer co zapis),
// „Wstaw” z poradnika redaktora (wiki/_editor-guide.html.twig), ostrzeżenie przed utratą niezapisanych zmian.
document.addEventListener("DOMContentLoaded", () => {
  if (!document.getElementsByClassName('wiki-edit').length) return

  /** @type {HTMLTextAreaElement|null} */
  const body = document.querySelector('.js-wiki-body')
  /** @type {HTMLFormElement|null} */
  const form = document.querySelector('.js-wiki-editor')
  const preview = document.querySelector('.js-wiki-preview')
  /** @type {HTMLElement|null} */
  const errors = document.querySelector('.js-wiki-errors')
  if (!body || !form || !preview || !errors) return

  let timer = undefined
  let sequence = 0
  const refresh = async () => {
    const current = ++sequence
    try {
      const result = await new Api('/wiki/_preview').post({ body: body.value })
      if (current !== sequence) return // nowszy podgląd już w drodze
      preview.innerHTML = result.html // HTML z renderera wiki (surowy HTML z treści jest wycinany po stronie serwera)
      errors.replaceChildren(...result.errors.map(msg => {
        const item = document.createElement('li')
        item.textContent = msg
        return item
      }))
      errors.hidden = !result.errors.length
    } catch {
      // Api pokazuje toast z błędem
    }
  }
  body.addEventListener('input', () => {
    clearTimeout(timer)
    timer = setTimeout(refresh, 400)
  })
  refresh()

  document.querySelectorAll('.js-wiki-insert').forEach(button => button.addEventListener('click', () => {
    // @ts-ignore
    insertBlock(body, button.dataset.snippet)
    // @ts-ignore
    document.getElementById('wiki-guide')?.close()
    body.focus()
    refresh()
  }))

  const initial = body.value
  let submitting = false
  form.addEventListener('submit', () => { submitting = true })
  window.addEventListener('beforeunload', event => {
    if (submitting || body.value === initial) return
    event.preventDefault()
    event.returnValue = ''
  })
})

/**
 * Wstawia blok w miejscu kursora, oddzielony pustą linią od poprzedzającego tekstu.
 * @param {HTMLTextAreaElement} textarea
 * @param {string} snippet
 */
function insertBlock(textarea, snippet) {
  const { selectionStart: start, selectionEnd: end, value } = textarea
  const before = value.slice(0, start)
  const separator = !before || before.endsWith('\n\n') ? '' : (before.endsWith('\n') ? '\n' : '\n\n')
  textarea.setRangeText(separator + snippet + '\n', start, end, 'end')
}

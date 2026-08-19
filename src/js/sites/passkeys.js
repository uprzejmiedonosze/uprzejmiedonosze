import Api from '../lib/Api'
import { message, error } from '../lib/toast'
import { supported, registerPasskey, isCancelled } from '../lib/webauthn'

function renderList(section, passkeys) {
  const list = section.querySelector('#passkeys-list')
  if (!list) return

  if (!passkeys.length) {
    list.innerHTML = '<li class="passkeys-empty">Nie masz jeszcze żadnego passkeya.</li>'
    return
  }

  list.innerHTML = passkeys.map(pk => `
    <li data-id="${pk.credential_id}">
      <span class="passkey-label">${pk.label}</span>
      <span class="passkey-meta">dodano ${formatDate(pk.created_at)}${pk.last_used_at ? `, ostatnio użyto ${formatDate(pk.last_used_at)}` : ''}</span>
      <button type="button" class="passkey-remove" data-id="${pk.credential_id}">Usuń</button>
    </li>
  `).join('')
}

function formatDate(iso) {
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return iso
  return d.toLocaleDateString('pl-PL')
}

async function removePasskey(section, credentialId) {
  if (!window.confirm('Usunąć ten passkey?')) return
  await new Api(`/api/passkey/${encodeURIComponent(credentialId)}`).delete()
  const li = section.querySelector(`li[data-id="${credentialId}"]`)
  li?.remove()
  const list = section.querySelector('#passkeys-list')
  if (list && !list.querySelector('li[data-id]')) {
    list.innerHTML = '<li class="passkeys-empty">Nie masz jeszcze żadnego passkeya.</li>'
  }
  message('Passkey usunięty.')
}

document.addEventListener('DOMContentLoaded', () => {
  const section = document.getElementById('passkeys-section')
  if (!section) return

  if (!supported()) {
    section.hidden = true
    return
  }

  const addButton = section.querySelector('#passkey-add')
  if (addButton) addButton.hidden = false

  addButton?.addEventListener('click', async () => {
    try {
      const passkeys = await registerPasskey()
      renderList(section, passkeys)
      message('Passkey dodany.')
    } catch (e) {
      // A cancelled/dismissed picker (Esc, timeout) is not an error worth showing.
      if (isCancelled(e)) return
      error(e?.message ?? 'Nie udało się dodać passkeya')
    }
  })

  section.addEventListener('click', async (event) => {
    const button = event.target.closest('.passkey-remove')
    if (!button) return
    try {
      await removePasskey(section, button.dataset.id)
    } catch (e) {
      error(e?.message ?? 'Nie udało się usunąć passkeya')
    }
  })
})

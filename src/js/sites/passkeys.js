import Api from '../lib/Api'
import { message, error } from '../lib/toast'
import { supported, registerPasskey, isCancelled } from '../lib/webauthn'

function renderList(section, passkeys) {
  const list = section.querySelector('#passkeys-list')
  if (!list) return

  if (!passkeys.length) {
    list.innerHTML = '<p class="passkeys-empty muted">Nie masz jeszcze żadnego passkeya.</p>'
    return
  }

  // Same .connected-app markup as /mcp's "Połączone aplikacje" list.
  list.innerHTML = passkeys.map(pk => `
    <div class="connected-app" data-id="${pk.credential_id}">
      <h3>${pk.label}</h3>
      <p class="muted">dodano ${formatDate(pk.created_at)}${pk.last_used_at ? `, ostatnio użyto ${formatDate(pk.last_used_at)}` : ''}</p>
      <button type="button" class="button small passkey-remove" data-id="${pk.credential_id}">Usuń</button>
    </div>
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
  const item = section.querySelector(`.connected-app[data-id="${credentialId}"]`)
  item?.remove()
  const list = section.querySelector('#passkeys-list')
  if (list && !list.querySelector('.connected-app[data-id]')) {
    list.innerHTML = '<p class="passkeys-empty muted">Nie masz jeszcze żadnego passkeya.</p>'
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

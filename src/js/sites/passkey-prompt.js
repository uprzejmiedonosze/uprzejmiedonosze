import Api from '../lib/Api'
import { error } from '../lib/toast'
import { supported, registerPasskey, isCancelled } from '../lib/webauthn'

document.addEventListener('DOMContentLoaded', () => {
  const prompt = document.getElementById('passkey-prompt')
  if (!prompt || !supported()) return

  prompt.hidden = false

  document.getElementById('passkey-prompt-add')?.addEventListener('click', async () => {
    try {
      await registerPasskey()
      prompt.hidden = true
    } catch (e) {
      // A cancelled/dismissed picker (Esc, timeout): leave the prompt up so
      // the user can retry, but don't show it as an error.
      if (isCancelled(e)) return
      error(e?.message ?? 'Nie udało się dodać passkeya')
    }
  })

  document.getElementById('passkey-prompt-dismiss')?.addEventListener('click', async () => {
    prompt.hidden = true
    await new Api('/api/passkey/prompt-dismiss').post({})
  })
})

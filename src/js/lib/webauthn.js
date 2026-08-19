import Api from './Api'

/** Feature-detects WebAuthn/passkey support in the current browser. */
export function supported() {
  return typeof window !== 'undefined' && !!window.PublicKeyCredential
}

/**
 * Whether a rejection from navigator.credentials.create()/get() is just the
 * user dismissing the native picker (Esc, "cancel", timeout) rather than a
 * real failure — these should be swallowed silently, never shown as an error.
 */
export function isCancelled(error) {
  return error?.name === 'NotAllowedError' || error?.name === 'AbortError'
}

/** Whether the browser can show passkeys in the native autofill (conditional UI). */
export async function conditionalAvailable() {
  if (!supported() || !window.PublicKeyCredential.isConditionalMediationAvailable) return false
  try {
    return await window.PublicKeyCredential.isConditionalMediationAvailable()
  } catch {
    return false
  }
}

function b64uToBuf(base64url) {
  const base64 = base64url.replace(/-/g, '+').replace(/_/g, '/')
  const padded = base64.padEnd(base64.length + (4 - base64.length % 4) % 4, '=')
  const binary = atob(padded)
  const bytes = new Uint8Array(binary.length)
  for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i)
  return bytes.buffer
}

function bufToB64u(buffer) {
  const bytes = new Uint8Array(buffer)
  let binary = ''
  for (let i = 0; i < bytes.byteLength; i++) binary += String.fromCharCode(bytes[i])
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

/**
 * Registers a new passkey: fetches creation options from the server, calls
 * navigator.credentials.create(), and posts the result back for verification.
 * Requires an existing session — see /api/passkey/register-options.
 */
export async function registerPasskey() {
  const options = await new Api('/api/passkey/register-options').post({})

  const publicKey = {
    ...options.publicKey,
    challenge: b64uToBuf(options.publicKey.challenge),
    user: {
      ...options.publicKey.user,
      id: b64uToBuf(options.publicKey.user.id)
    },
    excludeCredentials: (options.publicKey.excludeCredentials || []).map(cred => ({
      ...cred,
      id: b64uToBuf(cred.id)
    }))
  }

  const credential = await navigator.credentials.create({ publicKey })

  const body = {
    id: credential.id,
    clientDataJSON: bufToB64u(credential.response.clientDataJSON),
    attestationObject: bufToB64u(credential.response.attestationObject),
    transports: credential.response.getTransports ? credential.response.getTransports() : []
  }

  const result = await new Api('/api/passkey/register-verify').post(body)
  return result.passkeys
}

/**
 * Runs a passkey login: fetches assertion options, calls
 * navigator.credentials.get(), and posts the result back for verification.
 * Returns the Firebase custom token to exchange via signInWithCustomToken().
 *
 * @param {{ mediation?: 'conditional'|'optional'|'required'|'silent', signal?: AbortSignal }} opts
 */
export async function loginWithPasskey({ mediation, signal } = {}) {
  const options = await new Api('/api/passkey/login-options').post({})

  const publicKey = {
    ...options.publicKey,
    challenge: b64uToBuf(options.publicKey.challenge),
    allowCredentials: (options.publicKey.allowCredentials || []).map(cred => ({
      ...cred,
      id: b64uToBuf(cred.id)
    }))
  }

  const getOptions = { publicKey }
  if (mediation) getOptions.mediation = mediation
  if (signal) getOptions.signal = signal

  const assertion = await navigator.credentials.get(getOptions)

  const body = {
    id: assertion.id,
    clientDataJSON: bufToB64u(assertion.response.clientDataJSON),
    authenticatorData: bufToB64u(assertion.response.authenticatorData),
    signature: bufToB64u(assertion.response.signature),
    userHandle: assertion.response.userHandle ? bufToB64u(assertion.response.userHandle) : null
  }

  const result = await new Api('/api/passkey/login-verify').post(body)
  return result.customToken
}

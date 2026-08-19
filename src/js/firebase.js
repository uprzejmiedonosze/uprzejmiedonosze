import { initializeApp } from "firebase/app";
import { getAuth, onAuthStateChanged, GoogleAuthProvider, EmailAuthProvider, signInWithCustomToken, connectAuthEmulator } from "firebase/auth";
import * as firebaseui from 'firebaseui';

import Api from './lib/Api'
import { getFirebaseConfig, getClientId } from './lib/firebaseConfig'
import { supported as passkeySupported, conditionalAvailable, loginWithPasskey, isCancelled } from './lib/webauthn'

const currentScript = document.currentScript;
addEventListener("load", () => initLogin(currentScript));

let firebaseAuth = null;

// Aborts the in-flight conditional-UI (autofill) passkey request whenever a
// competing sign-in starts (the explicit button, or firebaseui itself) —
// only one navigator.credentials.get() can be pending at a time.
let conditionalAbort = null;

function getFirebaseAuth() {
    if(!firebaseAuth) {
        firebaseAuth = getAuth(initializeApp(getFirebaseConfig()))
        if (document.location.hostname === 'localhost' || document.location.hostname === '127.0.0.1') {
            connectAuthEmulator(firebaseAuth, "http://127.0.0.1:9099", { disableWarnings: true });
        }
    }
    return firebaseAuth
}

function initLogin(currentScript) {
    const signInSuccessUrl = currentScript?.getAttribute("signInSuccessUrl") ?? encodeURIComponent('/app/list');

    if (currentScript?.getAttribute("login-redirect")) {
        finishLogin(signInSuccessUrl)
        return
    }

    if (currentScript?.getAttribute("logout")) {
        doLogout()
        return
    }

    if (currentScript?.getAttribute("login")) {
        doLogin(signInSuccessUrl)
        initPasskeyLogin(signInSuccessUrl)
        return
    }
}

function doLogout() {
    getFirebaseAuth().signOut();
    window.location.replace('/');
}

function doLogin(signInSuccessUrl) {
    const emailAuthProvider = {
        provider: EmailAuthProvider.PROVIDER_ID,
        signInMethod: EmailAuthProvider.EMAIL_LINK_SIGN_IN_METHOD,
        forceSameDevice: false,
        disableSignUp: {
            status: false
        }
    }
    const googleAuthProvider = {
        provider: GoogleAuthProvider.PROVIDER_ID,
        clientId: getClientId()
    }

    let signInOptions = [googleAuthProvider, emailAuthProvider]

    var uiConfig = {
        'signInSuccessUrl': `/login-ok.html?next=${signInSuccessUrl}`,
        'callbacks': {
            'signInSuccessWithAuthResult': function (authResult, redirectUrl) {
                conditionalAbort?.abort()
                if (window.opener) {
                    window.close();
                    return false;
                }
                return true;
            }
        },
        'signInOptions': signInOptions,
        'tosUrl': '/regulamin.html',
        'privacyPolicyUrl': '/polityka-prywatnosci.html',
        'credentialHelper': firebaseui.auth.CredentialHelper.NONE,
        'adminRestrictedOperation': { status: false },
        'signInFlow': 'popup'
    };
    var ui = new firebaseui.auth.AuthUI(getFirebaseAuth());
    ui.start('#firebaseui-auth-container', uiConfig);

    // firebaseui renders its own email input asynchronously and re-renders it
    // on every screen change, so we can't just grab it once. Watch for it and
    // mark it for passkey autofill (autocomplete="webauthn") each time it
    // (re)appears.
    const container = document.getElementById('firebaseui-auth-container')
    if (container) {
        new MutationObserver(() => {
            const emailInput = container.querySelector('input[type=email], input[name=email]')
            if (emailInput && !emailInput.autocomplete?.includes('webauthn')) {
                emailInput.autocomplete = 'username webauthn'
            }
        }).observe(container, { childList: true, subtree: true })
    }
}

function setError(error) {
    if (typeof error === "object") {
        if (error.message)
            error = error.message
        else error = JSON.stringify(error);
    }
    const errorElement = document.querySelector("p.error");
    if (errorElement) errorElement.textContent = error;

    const footerElement = document.querySelector("footer h4");
    if (footerElement) footerElement.textContent = "błąd logowania";
}

/** Shared tail of every login method: Firebase ID token -> session -> redirect. */
async function postIdToken(idToken, signInSuccessUrl) {
    const api = new Api('/api/verify-token')
    await api.post(null, { "Authorization": `Bearer ${idToken}` })
    window.location.replace(decodeURIComponent(signInSuccessUrl))
}

function finishLogin(signInSuccessUrl) {
    onAuthStateChanged(getFirebaseAuth(), (user) => {
        if (!user) return setError('Error: missing user');
        user.getIdToken().then(async function (accessToken) {
            try {
                await postIdToken(accessToken, signInSuccessUrl)
            } catch(error) {
                setError(error)
            }
        });
    }, function (error) {
        setError(error);
    });
};

async function doPasskeyLogin(signInSuccessUrl, { mediation, signal } = {}) {
    const customToken = await loginWithPasskey({ mediation, signal })
    const credential = await signInWithCustomToken(getFirebaseAuth(), customToken)
    const idToken = await credential.user.getIdToken()
    await postIdToken(idToken, signInSuccessUrl)
}

function initPasskeyLogin(signInSuccessUrl) {
    if (!passkeySupported()) return

    const wrapper = document.getElementById('passkey-login')
    const button = document.getElementById('passkey-login-button')
    if (wrapper) wrapper.hidden = false

    button?.addEventListener('click', () => {
        conditionalAbort?.abort()
        doPasskeyLogin(signInSuccessUrl).catch((error) => {
            // A cancelled/dismissed picker is not an error worth showing.
            if (isCancelled(error)) return
            setError(error)
        })
    })

    conditionalAvailable().then((available) => {
        if (!available) return
        conditionalAbort = new AbortController()
        doPasskeyLogin(signInSuccessUrl, { mediation: 'conditional', signal: conditionalAbort.signal })
            .catch((error) => {
                if (isCancelled(error)) return
                setError(error)
            })

        // firebaseui doesn't render an email input until the user picks
        // "email" as a sign-in method — too late for the browser to show a
        // conditional-UI passkey suggestion on page load. Focusing this
        // dedicated (visually hidden) proxy input is what makes the native
        // suggestion pop up automatically, without any click.
        document.getElementById('passkey-conditional-input')?.focus()
    })
}

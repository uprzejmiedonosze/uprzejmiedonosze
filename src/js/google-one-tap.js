import { initializeApp } from "firebase/app";
import { getAuth, GoogleAuthProvider, signInWithCredential, connectAuthEmulator } from "firebase/auth";

import Api from './lib/Api'
import { getFirebaseConfig, getClientId } from './lib/firebaseConfig'

addEventListener("load", initOneTap);

let firebaseAuth = null;

function getFirebaseAuth() {
    if (!firebaseAuth) {
        firebaseAuth = getAuth(initializeApp(getFirebaseConfig()))
        if (document.location.hostname === 'localhost' || document.location.hostname === '127.0.0.1') {
            connectAuthEmulator(firebaseAuth, "http://127.0.0.1:9099", { disableWarnings: true });
        }
    }
    return firebaseAuth
}

function initOneTap() {
    if (!window.google?.accounts?.id) return;

    window.google.accounts.id.initialize({
        client_id: getClientId(),
        callback: handleCredentialResponse,
        auto_select: false,
        cancel_on_tap_outside: true,
        use_fedcm_for_prompt: true
    });
    window.google.accounts.id.prompt();
}

async function handleCredentialResponse(googleResponse) {
    try {
        const credential = GoogleAuthProvider.credential(googleResponse.credential);
        const userCredential = await signInWithCredential(getFirebaseAuth(), credential);
        const accessToken = await userCredential.user.getIdToken();
        const api = new Api('/api/verify-token');
        await api.post(null, { "Authorization": `Bearer ${accessToken}` });
        redirectAfterLogin();
    } catch (error) {
        console.error('Google One Tap sign-in failed', error);
    }
}

function redirectAfterLogin() {
    if (window.location.pathname.startsWith('/app')) {
        window.location.reload();
    } else {
        window.location.assign('/app');
    }
}

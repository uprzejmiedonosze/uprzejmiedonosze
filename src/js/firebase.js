import { initializeApp } from "firebase/app";
import { getAuth, onAuthStateChanged, GoogleAuthProvider, EmailAuthProvider, connectAuthEmulator } from "firebase/auth";
import * as firebaseui from 'firebaseui';

import Api from './lib/Api'
import { getFirebaseConfig, getClientId } from './lib/firebaseConfig'

const currentScript = document.currentScript;
addEventListener("load", () => initLogin(currentScript));

let firebaseAuth = null;

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

function finishLogin(signInSuccessUrl) {
    onAuthStateChanged(getFirebaseAuth(), (user) => {
        if (!user) return setError('Error: missing user');
        user.getIdToken().then(async function (accessToken) {
            try {
                const api = new Api('/api/verify-token')
                await api.post(null, {"Authorization": `Bearer ${accessToken}`})
                window.location.replace(decodeURIComponent(signInSuccessUrl))
            } catch(error) {
                setError(error)
            }
        });
    }, function (error) {
        setError(error);
    });
};


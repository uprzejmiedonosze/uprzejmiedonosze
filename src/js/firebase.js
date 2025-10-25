import { initializeApp } from "firebase/app";
import { getAuth, onAuthStateChanged, GoogleAuthProvider, EmailAuthProvider } from "firebase/auth";
import * as firebaseui from 'firebaseui';

import Api from './lib/Api'

const currentScript = document.currentScript;
addEventListener("load", () => initLogin(currentScript));

let firebaseAuth = null;

function getFirebaseAuth() {
    if(!firebaseAuth)
        firebaseAuth = getAuth(initializeApp(getFirebaseConfig()))
    return firebaseAuth
}

function initLogin(currentScript) {
    const signInSuccessUrl = currentScript?.getAttribute("signInSuccessUrl") ?? encodeURIComponent('/moje-zgloszenia.html');

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

function getFirebaseConfig() {
    const hostName = document.location.hostname
    if (hostName.includes('staging'))
        return {
            apiKey: "AIzaSyDXgjibECwejzudsm3YBQh3O5ponz7ArtI",
            authDomain: "auth-staging.uprzejmiedonosze.net",
            databaseURL: "https://uprzejmiedonosze-1494607701827.firebaseio.com",
            projectId: "uprzejmiedonosze-1494607701827",
            storageBucket: "uprzejmiedonosze-1494607701827.appspot.com",
            messagingSenderId: "509860799944",
            appId: "1:509860799944:web:5e24b16b56db3d44d98cfd"
        };

    if (hostName.includes('localhost'))
        return {
            apiKey: "AIzaSyA-gv2Ju8TfVc9e18sB898lXp0-4JrVIQ8",
            authDomain: "uprzejmie-donosze-dev.firebaseapp.com",
            databaseURL: "https://uprzejmie-donosze-dev.firebaseio.com",
            projectId: "uprzejmie-donosze-dev",
            storageBucket: "uprzejmie-donosze-dev.appspot.com",
            messagingSenderId: "961138564803"
        };

    return {
        apiKey: "AIzaSyC2vVIN-noxOw_7mPMvkb-AWwOk6qK1OJ8",
        authDomain: "auth.uprzejmiedonosze.net",
        databaseURL: "https://uprzejmie-donosze.firebaseio.com",
        projectId: "uprzejmie-donosze",
        storageBucket: "uprzejmie-donosze.appspot.com",
        messagingSenderId: "823788795198",
        appId: "1:823788795198:web:cc0192100ac2e16324286f"
    };
}

function getClientId() {
    const hostName = document.location.hostname
    if (hostName.includes('staging'))
        return '509860799944-7e8qe7knqkcjm5jbi932gg3tmgo657vf.apps.googleusercontent.com'
    if (hostName.includes('localhost'))
        return '961138564803-2id1ke8mjl1lr35div1i40dtrn1op369.apps.googleusercontent.com'
    return '823788795198-inlf6ld2q1o7up7vbkerb4gdu30bm5tu.apps.googleusercontent.com'
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


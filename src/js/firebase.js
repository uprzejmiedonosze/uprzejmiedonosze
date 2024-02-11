import firebase from 'firebase/compat/app';
import 'firebase/compat/auth';
import * as firebaseui from 'firebaseui';

const currentScript = document.currentScript;
addEventListener("load", () => initLogin(currentScript));

function initLogin(currentScript) {
    
    const signInSuccessUrl = encodeURIComponent(currentScript?.getAttribute("signInSuccessUrl") ?? '/moje-zgloszenia.html');
    const hostName = currentScript.getAttribute("host")

    firebase.initializeApp(getFirebaseConfig(hostName));

    if (currentScript?.getAttribute("login-redirect")) {
        finishLogin(signInSuccessUrl)
        return
    }

    if (currentScript?.getAttribute("logout")) {
        doLogout()
        return
    }

    doLogin(signInSuccessUrl, hostName)
}

function doLogout() {
    firebase.auth().signOut();
    window.location.replace('/');
}

function getFirebaseConfig(hostName) {
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

function doLogin(signInSuccessUrl, hostName) {
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
        'signInOptions': [{
            provider: firebase.auth.GoogleAuthProvider.PROVIDER_ID,
            clientId: null
        }, {
            provider: firebase.auth.EmailAuthProvider.PROVIDER_ID,
            signInMethod: firebase.auth.EmailAuthProvider.EMAIL_LINK_SIGN_IN_METHOD,
            forceSameDevice: false,
            disableSignUp: {
                status: false
            }
        }],
        'tosUrl': `${hostName}regulamin.html`,
        'privacyPolicyUrl': `${hostName}polityka-prywatnosci.html`,
        'credentialHelper': firebaseui.auth.CredentialHelper.NONE,
        'adminRestrictedOperation': { status: false },
        'signInFlow': 'popup'
    };
    var ui = new firebaseui.auth.AuthUI(firebase.auth());
    ui.start('#firebaseui-auth-container', uiConfig);
}

function setError(error) {
    if (typeof error === "object") {
        if (error.message)
            error = error.message
        else error = JSON.stringify(error);
    }
    $("p.error").text(error);
    $(".ui-footer h4").text("błąd logowania");
}

function finishLogin(signInSuccessUrl) {
    firebase.auth().onAuthStateChanged(function (user) {
        if (user) {
            user.getIdToken().then(function (accessToken) {
                $.ajax({
                    url: '/api/verify-token',
                    type: 'POST',
                    dataType: 'json',
                    contentType: 'application/json',
                    success: function (data) {
                        window.location.replace(decodeURIComponent(signInSuccessUrl));
                    },
                    error: setError,
                    headers: {
                        "Authorization": `Bearer ${accessToken}`
                    }
                });
            });
        } else {
            setError('Error: missing user');
        }
    }, function (error) {
        setError(error);
    });
};


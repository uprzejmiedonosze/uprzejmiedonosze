const hostName = window.location.hostname;

var firebaseConfig = {
    apiKey: "AIzaSyC2vVIN-noxOw_7mPMvkb-AWwOk6qK1OJ8",
    authDomain: "auth.uprzejmiedonosze.net",
    databaseURL: "https://uprzejmie-donosze.firebaseio.com",
    projectId: "uprzejmie-donosze",
    storageBucket: "uprzejmie-donosze.appspot.com",
    messagingSenderId: "823788795198",
    appId: "1:823788795198:web:cc0192100ac2e16324286f"
};

hostName.includes('staging') && (firebaseConfig = {
    apiKey: "AIzaSyDXgjibECwejzudsm3YBQh3O5ponz7ArtI",
    authDomain: "auth-staging.uprzejmiedonosze.net",
    databaseURL: "https://uprzejmiedonosze-1494607701827.firebaseio.com",
    projectId: "uprzejmiedonosze-1494607701827",
    storageBucket: "uprzejmiedonosze-1494607701827.appspot.com",
    messagingSenderId: "509860799944",
    appId: "1:509860799944:web:5e24b16b56db3d44d98cfd"
});

hostName.includes('localhost') && (firebaseConfig = {
    apiKey: "AIzaSyA-gv2Ju8TfVc9e18sB898lXp0-4JrVIQ8",
    authDomain: "uprzejmie-donosze-dev.firebaseapp.com",
    databaseURL: "https://uprzejmie-donosze-dev.firebaseio.com",
    projectId: "uprzejmie-donosze-dev",
    storageBucket: "uprzejmie-donosze-dev.appspot.com",
    messagingSenderId: "961138564803"
});

if (!firebase.apps.length) {
    firebase.initializeApp(firebaseConfig);
}

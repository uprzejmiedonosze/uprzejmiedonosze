const LATEST_CACHE_ID = 'v2';

/*
self.addEventListener('install', function (evt) {
    console.log("ServiceWorker installed");
    evt.waitUntil(precache());
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (cacheNames) {
            return Promise.all(
                cacheNames.map(function (cacheName) {
                    if (cacheName !== LATEST_CACHE_ID) {
                        return caches.delete(cacheName);
                    }
                })
            );
        }).then(function () {
            return self.clients.claim();
        })
    );
});

self.addEventListener('fetch', function (evt) {
    evt.respondWith(fromNetwork(evt.request, 100).catch(function () {
        return fromCache(evt.request);
    }));
});

function precache() {
    return caches.open(LATEST_CACHE_ID).then(function (cache) {
        return cache.addAll([
            '/',
            '/?homescreen=1',
            '/index.html',
            '/index.html?homescreen=1',
            '/projekt.html',
            '/robtodobrze.html',
            '/changelog.html',
            '/przepisy.html',

            '/css/style-%CSS_HASH%.css',

            '/js/script-%JS_HASH%.js',
            '/js/load-image-%JS_HASH%.js',
            '/js/load-image-scale-%JS_HASH%.js',
            '/js/load-image-meta-%JS_HASH%.js',
            '/js/load-image-orientation-%JS_HASH%.js',
            '/js/load-image-exif-%JS_HASH%.js',
            '/js/load-image-exif-map-%JS_HASH%.js'
        ]);
    });
}

function fromNetwork(request, timeout) {
    return new Promise(function (fulfill, reject) {
        var timeoutId = setTimeout(reject, timeout);
        console.log(request);
        fetch(request).then(function (response) {
            clearTimeout(timeoutId);
            fulfill(response);
        }, reject);
    });
}

function fromCache(request) {
    return caches.open(LATEST_CACHE_ID).then(function (cache) {
        return cache.match(request).then(function (matching) {
            console.log(matching);
            return matching || Promise.reject('no-match');
        });
    });
}
*/
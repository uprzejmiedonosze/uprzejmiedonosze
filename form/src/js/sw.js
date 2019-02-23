self.addEventListener('install', function(e) {
    e.waitUntil(
        caches.open('uprzejmiedonosze').then(function(cache) {
            return cache.addAll([
                '/',
                '/?homescreen=1',
                '/index.html',
                '/index.html?homescreen=1',
                '/projekt.html',
                '/robtodobrze.html',
                '/changelog.html',
                '/przepisy.html',

                '/img/apple-touch-icon.png',
                '/img/b36.png',
                '/img/d18.png',
                '/img/d40.png',
                '/img/d46.png',
                '/img/d52.png',
                '/img/przyklad-1.jpg',
                '/img/przyklad-2.jpg',
                '/img/przyklad-3.jpg',
                '/img/przyklad-4.jpg',
                '/img/robtodobrze-radni-s.jpg',
                '/img/robtodobrze-radni.jpg',
                '/img/robtodobrze-s.jpg',
                '/img/robtodobrze.jpg',
                '/img/rtd-mandat-cmyk-print.pdf',
                '/img/sm-tvn24-s.jpg',
                '/img/sm-tvn24.jpg',
                '/img/splash.png',
                '/img/t30a.gif',
                '/img/t30b.gif',
                '/img/t30c.gif',
                '/img/t30d.gif',
                '/img/t30e.gif',
                '/img/t30f.gif',
                '/img/t30g.gif',
                '/img/t30h.gif',
                '/img/t30i.gif',
                '/img/uprzejmiedonosze.png',

                '/css/style-%CSS_HASH%.css',

                '/js/script-%JS_HASH%.js',
                '/js/load-image-%JS_HASH%.js',
                '/js/load-image-scale-%JS_HASH%.js',
                '/js/load-image-meta-%JS_HASH%.js',
                '/js/load-image-orientation-%JS_HASH%.js',
                '/js/load-image-exif-%JS_HASH%.js',
                '/js/load-image-exif-map-%JS_HASH%.js'

            ], { mode: 'same-origin', redirect: 'manual' });
        })
    );
});

self.addEventListener('activate',  function(event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', function(event) {
    event.respondWith(
        caches.match(event.request).then(function(response) {
            return response || fetch(event.request);
        })
    );
});
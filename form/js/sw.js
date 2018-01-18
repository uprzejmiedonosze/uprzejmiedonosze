self.addEventListener('install', function(e) {
	e.waitUntil(
		caches.open('airhorner').then(function(cache) {
			return cache.addAll([
				'/',
				'/index.html',
				'/index.html?homescreen=1',
				'/?homescreen=1',
				'/start.html',
				'/projekt.html',
				'/robtodobrze.html',
				'/img/robtodobrze.jpg',
				'/img/robtodobrze-s.jpg',
				'/img/sm-tvn24.jpg',
				'/img/sm-tvn24-s.jpg',
				'/img/robtodobrze-radni.jpg',
				'/img/robtodobrze-radni-s.jpg',
				'/img/przyklad-1.jpg',
				'/img/przyklad-2.jpg',
				'/css/style.css',
				'/js/script.js'
			]);
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
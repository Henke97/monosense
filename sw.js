self.addEventListener("install", event => {
    console.log("Service worker installerad.");
    self.skipWaiting();
});

self.addEventListener("activate", event => {
    console.log("Service worker aktiverad.");
});

self.addEventListener("fetch", event => {
    event.respondWith(fetch(event.request));
});

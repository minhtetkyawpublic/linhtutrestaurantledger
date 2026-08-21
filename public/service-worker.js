const APP_SCOPE_PATH = new URL(self.registration.scope).pathname.replace(
    /\/$/,
    "",
);
const API_PREFIX = `${APP_SCOPE_PATH}/api`;
const STATIC_CACHE = "linh-tut-restaurant-shell-e21f352c0aa5";

self.addEventListener("install", (event) => {
    event.waitUntil(
        caches
            .open(STATIC_CACHE)
            .then((cache) =>
                cache.addAll([
                    "./offline.html",
                    "./manifest.webmanifest",
                    "./linhtuticon.jpg",
                    "./icon-180.png",
                    "./icon-192.png",
                    "./icon-512.png",
                    "./icon-maskable-512.png",
                ]),
            ),
    );
    self.skipWaiting();
});

self.addEventListener("activate", (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((cacheNames) =>
                Promise.all(
                    cacheNames.map((cacheName) => {
                        if (
                            cacheName.startsWith(
                                "linh-tut-restaurant-shell-",
                            ) &&
                            cacheName !== STATIC_CACHE
                        ) {
                            return caches.delete(cacheName);
                        }
                        return null;
                    }),
                ),
            )
            .then(() => self.clients.claim()),
    );
});

const shouldBypassCache = (request, url) => {
    if (request.method !== "GET") return true;
    if (url.origin !== self.location.origin) return true;
    if (url.pathname.startsWith(API_PREFIX)) return true;
    return false;
};

self.addEventListener("fetch", (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (shouldBypassCache(request, url)) return;

    if (request.mode === "navigate") {
        // The Laravel shell contains a session-specific CSRF token. Never put
        // authenticated navigation HTML into Cache Storage; use a static,
        // non-sensitive offline page instead.
        event.respondWith(
            fetch(request).catch(() => caches.match("./offline.html")),
        );
        return;
    }

    event.respondWith(
        caches.match(request).then((cached) => {
            if (cached) return cached;

            return fetch(request).then((response) => {
                if (response.ok) {
                    const clone = response.clone();
                    caches
                        .open(STATIC_CACHE)
                        .then((cache) => cache.put(request, clone));
                }
                return response;
            });
        }),
    );
});

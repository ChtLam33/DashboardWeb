// sw.js – Service Worker pour les notifications barriques

self.addEventListener("push", event => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = {
            title: "Notification",
            body: event.data ? event.data.text() : ""
        };
    }

    const title = data.title || "Notification barriques";
    const body  = data.body  || "";
    const url   = data.url   || "https://prod.lamothe-despujols.com/barriques/";

    const options = {
        body: body,
        icon: "/barriques/icon.png",   // garde ton icon.png
        badge: "/barriques/icon.png",
        data: {
            url: url                   // on garde l'URL au cas où
        }
    };

    event.waitUntil(
        self.registration.showNotification(title, options)
    );
});

// VERSION SIMPLE : au clic, on ouvre toujours la page barriques
self.addEventListener("notificationclick", function(event) {
    event.notification.close();

    const url = "https://prod.lamothe-despujols.com/barriques/";

    event.waitUntil(
        clients.openWindow(url)
    );
});
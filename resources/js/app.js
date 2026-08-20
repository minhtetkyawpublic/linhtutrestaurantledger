import { createRoot } from "react-dom/client";
import App from "./components/App";
import { createElement } from "react";

import "./bootstrap";

const registerServiceWorker = () => {
    if (!("serviceWorker" in navigator) || import.meta.env.DEV) {
        return;
    }

    navigator.serviceWorker
        .register(`${window.__APP_BASE_PATH || ""}/service-worker.js`, {
            scope: `${window.__APP_BASE_PATH || ""}/`,
        })
        .then((registration) => {
            if (registration.waiting) {
                window.dispatchEvent(new CustomEvent("ledger:update-ready"));
            }
            registration.addEventListener("updatefound", () => {
                const worker = registration.installing;
                worker?.addEventListener("statechange", () => {
                    if (
                        worker.state === "installed" &&
                        navigator.serviceWorker.controller
                    ) {
                        window.dispatchEvent(
                            new CustomEvent("ledger:update-ready"),
                        );
                    }
                });
            });
        })
        .catch((error) => {
            console.warn("Service worker registration failed", error);
        });
};

const container = document.getElementById("app-root");

if (container) {
    const root = createRoot(container);
    root.render(createElement(App));
}

window.addEventListener("load", registerServiceWorker);

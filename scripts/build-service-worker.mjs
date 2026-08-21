import { createHash } from "node:crypto";
import fs from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

const projectRoot = path.resolve(
    path.dirname(fileURLToPath(import.meta.url)),
    "..",
);
const manifestPath = path.join(projectRoot, "public", "build", "manifest.json");
const templatePath = path.join(
    projectRoot,
    "resources",
    "pwa",
    "service-worker.js",
);
const destinationPath = path.join(projectRoot, "public", "service-worker.js");

const template = await fs.readFile(templatePath, "utf8");
const versionFiles = [
    manifestPath,
    templatePath,
    path.join(projectRoot, "public", "manifest.webmanifest"),
    path.join(projectRoot, "public", "offline.html"),
    path.join(projectRoot, "public", "linhtuticon.jpg"),
    path.join(projectRoot, "public", "icon-180.png"),
    path.join(projectRoot, "public", "icon-192.png"),
    path.join(projectRoot, "public", "icon-512.png"),
    path.join(projectRoot, "public", "icon-maskable-512.png"),
];
const versionHash = createHash("sha256");
for (const file of versionFiles) versionHash.update(await fs.readFile(file));
const version = versionHash.digest("hex").slice(0, 12);

if (!template.includes("__APP_VERSION__")) {
    throw new Error("Service-worker template is missing __APP_VERSION__.");
}

await fs.writeFile(
    destinationPath,
    template.replaceAll("__APP_VERSION__", version),
    "utf8",
);

console.log(`Generated service worker for build ${version}.`);

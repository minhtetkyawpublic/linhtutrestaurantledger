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

const manifest = await fs.readFile(manifestPath, "utf8");
const version = createHash("sha256")
    .update(manifest)
    .digest("hex")
    .slice(0, 12);
const template = await fs.readFile(templatePath, "utf8");

if (!template.includes("__APP_VERSION__")) {
    throw new Error("Service-worker template is missing __APP_VERSION__.");
}

await fs.writeFile(
    destinationPath,
    template.replaceAll("__APP_VERSION__", version),
    "utf8",
);

console.log(`Generated service worker for build ${version}.`);

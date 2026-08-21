import { createHash } from "node:crypto";
import fs from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";
import sharp from "sharp";

const __filename = fileURLToPath(import.meta.url);
const projectRoot = path.resolve(path.dirname(__filename), "..");
const requireProduction = process.argv.includes("--require-production");

const requireFile = async (relativePath, issues, label, details = "") => {
    const absolutePath = path.join(projectRoot, relativePath);
    try {
        await fs.access(absolutePath);
        return absolutePath;
    } catch {
        issues.push(
            `${label} missing: ${relativePath}${details ? ` (${details})` : ""}`,
        );
        return null;
    }
};

const readJson = async (absolutePath) => {
    const raw = await fs.readFile(absolutePath, "utf8");
    return JSON.parse(raw);
};

const readText = async (absolutePath) => {
    try {
        return await fs.readFile(absolutePath, "utf8");
    } catch {
        return "";
    }
};

const parseEnv = async (absolutePath) => {
    try {
        const raw = await fs.readFile(absolutePath, "utf8");
        const lines = raw.split(/\r?\n/);
        const env = {};

        for (const line of lines) {
            const trimmed = line.trim();

            if (!trimmed || trimmed.startsWith("#")) {
                continue;
            }

            const index = trimmed.indexOf("=");
            if (index === -1) {
                continue;
            }

            const key = trimmed.substring(0, index).trim();
            let value = trimmed.substring(index + 1).trim();

            if (value.startsWith('"') && value.endsWith('"')) {
                value = value.slice(1, -1);
            }

            env[key] = value;
        }

        return env;
    } catch {
        return {};
    }
};

const main = async () => {
    const issues = [];
    const warnings = [];

    const requiredFiles = [
        "README.md",
        "composer.json",
        "composer.lock",
        "package.json",
        "package-lock.json",
        "vite.config.js",
        ".env.example",
        ".env.production.example",
        ".htaccess",
        "public/.htaccess",
        "public/index.php",
        "routes/web.php",
        "routes/api.php",
        "resources/views/welcome.blade.php",
        "resources/js/app.js",
        "resources/js/bootstrap.js",
        "public/manifest.webmanifest",
        "public/offline.html",
        "public/service-worker.js",
        "resources/pwa/service-worker.js",
        "scripts/build-service-worker.mjs",
        "linhtuticon.jpg",
        "public/icon-48.png",
        "public/icon-180.png",
        "public/icon-192.png",
        "public/icon-512.png",
        "public/icon-maskable-512.png",
    ];

    for (const relativePath of requiredFiles) {
        await requireFile(relativePath, issues, "Repository hardening check");
    }

    const manifest = await requireFile(
        "public/build/manifest.json",
        issues,
        "Build manifest check",
    );

    let expectedServiceWorkerVersion = null;
    if (manifest) {
        try {
            const versionFiles = [
                "public/build/manifest.json",
                "resources/pwa/service-worker.js",
                "public/manifest.webmanifest",
                "public/offline.html",
                "public/icon-180.png",
                "public/icon-192.png",
                "public/icon-512.png",
                "public/icon-maskable-512.png",
            ];
            const versionHash = createHash("sha256");
            for (const relativePath of versionFiles) {
                versionHash.update(
                    await fs.readFile(path.join(projectRoot, relativePath)),
                );
            }
            expectedServiceWorkerVersion = versionHash
                .digest("hex")
                .slice(0, 12);
            const json = await readJson(manifest);
            let missingAsset = false;
            for (const entry of Object.values(json)) {
                const fileName = entry?.file;
                if (!fileName) {
                    issues.push("Manifest entry is missing an asset filename.");
                    missingAsset = true;
                    continue;
                }
                const assetPath = path.join(
                    projectRoot,
                    "public",
                    "build",
                    fileName,
                );
                try {
                    await fs.access(assetPath);
                } catch {
                    issues.push(
                        `Manifest references missing asset file: public/build/${fileName}`,
                    );
                    missingAsset = true;
                }
            }
            if (!missingAsset) {
                console.log("Manifest references all recorded build assets.");
            }
        } catch (error) {
            issues.push(
                `Unable to parse public/build/manifest.json: ${error.message}`,
            );
        }
    }

    const gitignoreText = await readText(path.join(projectRoot, ".gitignore"));
    const ignoresBuild = /^(\s*|\/)public\/build(\/?|\/\*.*)?$/m.test(
        gitignoreText,
    );
    if (ignoresBuild) {
        issues.push(
            "public/build is ignored in .gitignore; it should remain tracked for Hostinger deployments.",
        );
    }

    try {
        const composer = await readJson(
            path.join(projectRoot, "composer.json"),
        );
        if (!composer?.require?.php) {
            issues.push(
                "composer.json does not specify PHP platform requirement.",
            );
        }
        for (const extension of ["ext-pdo", "ext-pdo_mysql"]) {
            if (!composer?.require?.[extension]) {
                issues.push(
                    `composer.json must explicitly require ${extension} for the MySQL deployment.`,
                );
            }
        }
    } catch (error) {
        issues.push(`Unable to read composer.json: ${error.message}`);
    }

    const envPath = path.join(projectRoot, ".env");
    const env = await parseEnv(envPath);
    const productionMode =
        String(env.APP_ENV || "").toLowerCase() === "production";
    const checkProduction = requireProduction || productionMode;

    const productionExample = await parseEnv(
        path.join(projectRoot, ".env.production.example"),
    );
    const requiredProductionExampleValues = {
        APP_ENV: "production",
        APP_DEBUG: "false",
        APP_TIMEZONE: "asia/yangon",
        DB_CONNECTION: "mysql",
        SESSION_DRIVER: "database",
        SESSION_ENCRYPT: "true",
        SESSION_SECURE_COOKIE: "true",
        CACHE_STORE: "file",
        QUEUE_CONNECTION: "sync",
        FILESYSTEM_DISK: "local",
    };
    for (const [key, expected] of Object.entries(
        requiredProductionExampleValues,
    )) {
        if (String(productionExample[key] || "").toLowerCase() !== expected) {
            issues.push(`.env.production.example must set ${key}=${expected}.`);
        }
    }
    for (const key of ["SESSION_COOKIE", "SESSION_PATH"]) {
        if (!String(productionExample[key] || "").trim()) {
            issues.push(
                `.env.production.example must provide a non-empty ${key} template value.`,
            );
        }
    }
    if (!/^\/.+\/$/.test(productionExample.SESSION_PATH || "")) {
        issues.push(
            ".env.production.example SESSION_PATH must be a folder-aware path with leading and trailing slashes.",
        );
    }
    if (!String(productionExample.APP_URL || "").startsWith("https://")) {
        issues.push(
            ".env.production.example APP_URL must use an HTTPS production template.",
        );
    }

    const requiredProdKeys = [
        "APP_TIMEZONE",
        "SESSION_COOKIE",
        "SESSION_PATH",
        "SESSION_SECURE_COOKIE",
        "APP_KEY",
        "APP_URL",
        "APP_ENV",
    ];

    if (checkProduction) {
        for (const key of requiredProdKeys) {
            const value = (env[key] || "").trim();
            if (!value) {
                issues.push(
                    `Production readiness check failed: missing ${key}.`,
                );
            }
        }

        if (env.SESSION_PATH && !env.SESSION_PATH.startsWith("/")) {
            issues.push(
                'Production readiness check failed: SESSION_PATH must start with "/".',
            );
        }
    } else {
        for (const key of requiredProdKeys) {
            if (!env[key]) {
                warnings.push(
                    `Non-production environment detected; optional production check skipped for ${key}.`,
                );
                break;
            }
        }
    }

    const rootHtaccessPath = path.join(projectRoot, ".htaccess");
    const rootHtaccess = await fs
        .readFile(rootHtaccessPath, "utf8")
        .catch(() => "");
    const requiredRootHtaccessRules = [
        "Options -Indexes -MultiViews",
        "node_modules|reference_docs|resources",
        "public/$1 [L,NS,QSA]",
        "RewriteRule ^ index.php",
    ];

    for (const expected of requiredRootHtaccessRules) {
        if (!rootHtaccess.includes(expected)) {
            issues.push(
                `Fallback hosting hardening rule missing in root .htaccess: ${expected}`,
            );
        }
    }

    const publicHtaccess = await readText(
        path.join(projectRoot, "public", ".htaccess"),
    );
    for (const header of [
        "X-Content-Type-Options",
        "X-Frame-Options",
        "Referrer-Policy",
        "Permissions-Policy",
    ]) {
        if (!publicHtaccess.includes(header)) {
            issues.push(
                `Preferred public-root .htaccess is missing security header ${header}.`,
            );
        }
    }

    const swContent = await readText(
        path.join(projectRoot, "public", "service-worker.js"),
    );
    const hasAuthenticatedApiBypass =
        swContent.includes("url.pathname.startsWith(API_PREFIX)") ||
        /url\.pathname\.startsWith\(/.test(swContent);
    const hasMethodGuard = /request\.method\s*!==\s*["']GET["']/.test(
        swContent,
    );

    if (!hasAuthenticatedApiBypass || !hasMethodGuard) {
        issues.push(
            "Service worker may cache requests that should not be cached (for example API/auth requests).",
        );
    }
    const navigationStart = swContent.indexOf(
        'if (request.mode === "navigate")',
    );
    const navigationEnd = swContent.indexOf(
        "\n        return;",
        navigationStart,
    );
    const navigationBlock =
        navigationStart >= 0 && navigationEnd > navigationStart
            ? swContent.slice(navigationStart, navigationEnd)
            : "";
    if (
        !navigationBlock.includes('caches.match("./offline.html")') ||
        navigationBlock.includes("cache.put(")
    ) {
        issues.push(
            "Service worker must use the static offline page without caching session-specific navigation HTML.",
        );
    }
    if (
        !expectedServiceWorkerVersion ||
        !swContent.includes(
            `linh-tut-restaurant-shell-${expectedServiceWorkerVersion}`,
        ) ||
        swContent.includes("__APP_VERSION__")
    ) {
        issues.push(
            "Generated service worker does not match the current frontend and PWA shell assets.",
        );
    }

    try {
        const pwaManifest = await readJson(
            path.join(projectRoot, "public", "manifest.webmanifest"),
        );
        for (const icon of pwaManifest.icons || []) {
            const iconPath = path.join(projectRoot, "public", icon.src);
            const stats = await fs.stat(iconPath);
            if (stats.size === 0) {
                issues.push(`PWA icon is empty: public/${icon.src}`);
            }
            if (icon.type === "image/png") {
                const expectedSize = Number(
                    String(icon.sizes || "").split("x")[0],
                );
                const metadata = await sharp(iconPath).metadata();
                if (
                    !expectedSize ||
                    metadata.width !== expectedSize ||
                    metadata.height !== expectedSize
                ) {
                    issues.push(
                        `PWA icon dimensions do not match its manifest size: public/${icon.src}`,
                    );
                }
            }
        }
        if (!Array.isArray(pwaManifest.icons) || pwaManifest.icons.length < 2) {
            issues.push(
                "PWA manifest must include install icons for phone home screens.",
            );
        }
        if (
            !pwaManifest.icons?.some((icon) =>
                String(icon.purpose || "")
                    .split(" ")
                    .includes("maskable"),
            )
        ) {
            issues.push("PWA manifest must include a safe maskable app icon.");
        }
        if (
            pwaManifest.display !== "standalone" ||
            pwaManifest.prefer_related_applications !== false
        ) {
            issues.push(
                "PWA manifest must request standalone installation without a related native app.",
            );
        }
        if (pwaManifest.start_url !== "." || pwaManifest.scope !== ".") {
            issues.push(
                "PWA start_url and scope must remain relative for nested-folder deployment.",
            );
        }
    } catch (error) {
        issues.push(`PWA manifest/icon verification failed: ${error.message}`);
    }

    if (issues.length > 0) {
        console.error("Hostinger deployment verification failed:");
        for (const issue of issues) {
            console.error(`- ${issue}`);
        }
        process.exit(1);
    }

    if (warnings.length > 0) {
        console.warn("Hostinger deployment verification warnings:");
        for (const warning of warnings) {
            console.warn(`- ${warning}`);
        }
        process.exit(0);
    }

    console.log("Hostinger deployment verification passed.");
};

main().catch((error) => {
    console.error("Hostinger deployment verification errored:", error.message);
    process.exit(1);
});

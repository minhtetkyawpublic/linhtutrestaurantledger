import assert from "node:assert/strict";
import fs from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { chromium } from "playwright-core";

const projectRoot = path.resolve(
    path.dirname(fileURLToPath(import.meta.url)),
    "..",
);
const chromePath =
    process.env.CHROME_PATH ||
    "C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe";
const baseUrl = process.env.APP_TEST_URL || "http://127.0.0.1:8000";
const artifacts = path.join(projectRoot, "storage", "app", "test-artifacts");

await fs.access(chromePath);
await fs.mkdir(artifacts, { recursive: true });

const browser = await chromium.launch({
    executablePath: chromePath,
    headless: true,
});
const context = await browser.newContext({
    viewport: { width: 390, height: 844 },
    deviceScaleFactor: 1,
    isMobile: true,
    hasTouch: true,
});
const page = await context.newPage();
const runtimeErrors = [];

page.on("pageerror", (error) => runtimeErrors.push(error.message));
page.on("console", (message) => {
    if (message.type() === "error") runtimeErrors.push(message.text());
});

try {
    const response = await page.goto(baseUrl, {
        waitUntil: "domcontentloaded",
    });
    assert.equal(response?.status(), 200, "Root page should return HTTP 200");
    await page
        .getByRole("heading", { name: "Lin Htut Restaurant Ledger" })
        .waitFor();

    const visual = await page.locator(".auth-card").evaluate((card) => {
        const style = getComputedStyle(card);
        return {
            backgroundColor: style.backgroundColor,
            borderRadius: style.borderRadius,
            pageWidth: document.documentElement.scrollWidth,
            viewportWidth: window.innerWidth,
        };
    });
    assert.notEqual(
        visual.backgroundColor,
        "rgba(0, 0, 0, 0)",
        "Compiled CSS should style the login card",
    );
    assert.notEqual(
        visual.borderRadius,
        "0px",
        "Login card should be designed",
    );
    assert.ok(
        visual.pageWidth <= visual.viewportWidth + 1,
        `Login page overflows mobile viewport (${visual.pageWidth}px > ${visual.viewportWidth}px)`,
    );
    const brandLoaded = await page
        .locator(".brand-icon")
        .evaluate((image) => image.complete && image.naturalWidth >= 192);
    assert.equal(brandLoaded, true, "The Lin Htut app icon should load");
    const installManifest = await page.evaluate(async () => {
        const manifestLink = document.querySelector('link[rel="manifest"]');
        const manifest = await fetch(manifestLink.href).then((item) =>
            item.json(),
        );
        return {
            display: manifest.display,
            icons: manifest.icons,
            startUrl: manifest.start_url,
            scope: manifest.scope,
        };
    });
    assert.equal(installManifest.display, "standalone");
    assert.equal(installManifest.startUrl, ".");
    assert.equal(installManifest.scope, ".");
    assert.ok(
        installManifest.icons.some((icon) => icon.purpose === "maskable"),
        "Install manifest should provide a maskable icon",
    );

    await page.getByLabel("Email").fill("admin@example.com");
    await page.getByLabel("Password").fill("ChangeMe123!");
    await page.getByRole("button", { name: "Login" }).click();
    await page.locator(".app-shell").waitFor();
    const myanmarLocaleButton = page.getByRole("button", { name: "MY" });
    if (await myanmarLocaleButton.isVisible().catch(() => false)) {
        await Promise.all([
            page.waitForResponse(
                (item) => item.url().endsWith("/api/auth/locale") && item.ok(),
            ),
            myanmarLocaleButton.click(),
        ]);
    }
    await page.getByText("Ready to record today’s business?").waitFor();

    await page.goto(`${baseUrl}/reports`, {
        waitUntil: "domcontentloaded",
    });
    await page.getByRole("heading", { name: "Reports" }).waitFor();
    assert.equal(page.url(), `${baseUrl}/reports`);
    assert.ok(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth + 1,
        ),
        "Reports page should not overflow the mobile viewport",
    );

    await page.screenshot({
        path: path.join(artifacts, "mobile-reports.png"),
        fullPage: true,
    });

    await page.getByRole("button", { name: "Filters" }).click();
    await page.waitForTimeout(220);
    const modalPosition = await page
        .locator(".modal-dialog")
        .evaluate((item) => {
            const rect = item.getBoundingClientRect();
            return {
                top: rect.top,
                bottom: window.innerHeight - rect.bottom,
                centerDelta: Math.abs(
                    rect.top + rect.height / 2 - window.innerHeight / 2,
                ),
            };
        });
    assert.ok(modalPosition.top > 8, "Modal should not attach to the top edge");
    assert.ok(
        modalPosition.bottom > 8,
        "Modal should not appear as a bottom sheet",
    );
    assert.ok(
        modalPosition.centerDelta < 80,
        "Modal should be centered in the phone viewport",
    );
    await page.screenshot({
        path: path.join(artifacts, "mobile-report-filters.png"),
    });
    await page.getByRole("button", { name: "Close" }).click();

    await Promise.all([
        page.waitForResponse(
            (item) => item.url().endsWith("/api/auth/locale") && item.ok(),
        ),
        page.getByRole("button", { name: "EN" }).click(),
    ]);
    await page.getByRole("heading", { name: "အစီရင်ခံစာများ" }).waitFor();
    assert.ok(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth + 1,
        ),
        "Myanmar reports page should not overflow the mobile viewport",
    );
    await page.screenshot({
        path: path.join(artifacts, "mobile-reports-myanmar.png"),
        fullPage: true,
    });
    await Promise.all([
        page.waitForResponse(
            (item) => item.url().endsWith("/api/auth/locale") && item.ok(),
        ),
        page.getByRole("button", { name: "MY" }).click(),
    ]);
    await page.getByRole("heading", { name: "Reports" }).waitFor();

    assert.deepEqual(
        runtimeErrors,
        [],
        `Browser errors: ${runtimeErrors.join(" | ")}`,
    );

    await page.getByRole("button", { name: "Logout" }).click();
    await page
        .getByRole("heading", { name: "Lin Htut Restaurant Ledger" })
        .waitFor();
    await page.getByLabel("Email").fill("admin@example.com");
    await page.getByLabel("Password").fill("ChangeMe123!");
    await page.getByRole("button", { name: "Login" }).click();
    await page.locator(".app-shell").waitFor();

    await page.evaluate(async () => {
        await navigator.serviceWorker.ready;
    });
    await context.setOffline(true);
    await page.reload({ waitUntil: "domcontentloaded" });
    await page.getByRole("heading", { name: "You are offline" }).waitFor();
    await page.screenshot({
        path: path.join(artifacts, "mobile-offline.png"),
        fullPage: true,
    });
    await context.setOffline(false);

    console.log(
        `Mobile browser test passed at ${baseUrl}; screenshot: ${path.join(artifacts, "mobile-reports.png")}`,
    );
} finally {
    await browser.close();
}

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
const hotUrl = (
    await fs.readFile(path.join(projectRoot, "public", "hot"), "utf8")
).trim();
const artifacts = path.join(projectRoot, "storage", "app", "test-artifacts");

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
page.setDefaultTimeout(8000);
const errors = [];
page.on("pageerror", (error) => errors.push(error.message));
page.on("console", (message) => {
    if (message.type() === "error") errors.push(message.text());
});
await page.route("**/linhtuticon.jpg", async (route) => {
    await route.fulfill({
        status: 200,
        contentType: "image/jpeg",
        body: await fs.readFile(path.join(projectRoot, "linhtuticon.jpg")),
    });
});

await page.route("**/api/**", async (route) => {
    const pathname = new URL(route.request().url()).pathname;
    const responses = {
        "/api/auth/session": {
            csrf_token: "test",
            user: { id: 1, name: "Preview", ui_locale: "en" },
            permissions: ["view_dashboard", "view_reports"],
        },
        "/api/dashboard": {
            total_sales: 125000,
            sales_count: 18,
            total_customer_debt: 32000,
            customers_owe_count: 4,
            recent_activity: [],
        },
        "/api/reports/filter-options": {
            customers: [],
            categories: [],
            curries: [],
        },
        "/api/reports/sales-summary": {
            total_sales: 125000,
            total_discounts: 2500,
            total_paid_at_sale: 95000,
            total_new_sale_debt: 30000,
            customer_payments_received: 12000,
            money_lent_or_returned: 0,
            reversed_sales_count: 0,
            reversed_ledger_entries_count: 0,
        },
        "/api/reports/customer-balances": {
            total_outstanding: 32000,
            total_shop_owes: 0,
        },
        "/api/reports/top-curries": {},
    };
    await route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify(responses[pathname] || []),
    });
});

try {
    console.log(`Opening mobile layout preview at ${hotUrl}.`);
    await page.goto(hotUrl, {
        waitUntil: "domcontentloaded",
    });
    await page.setContent(
        `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="test"><script>window.__APP_BASE_PATH = "";</script><link rel="stylesheet" href="${hotUrl}/resources/css/app.css"><script type="module">import RefreshRuntime from "${hotUrl}/@react-refresh"; RefreshRuntime.injectIntoGlobalHook(window); window.$RefreshReg$ = () => {}; window.$RefreshSig$ = () => (type) => type; window.__vite_plugin_react_preamble_installed__ = true;</script></head><body><div id="app-root"></div><script type="module" src="${hotUrl}/resources/js/app.js"></script></body></html>`,
        { waitUntil: "domcontentloaded" },
    );
    errors.length = 0;
    await page.locator(".app-shell").waitFor();
    console.log("App shell rendered.");
    await page.getByRole("button", { name: /Reports/ }).click();
    await page.getByRole("heading", { name: "Reports" }).waitFor();
    console.log("Reports screen rendered.");

    const dimensions = await page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        viewportWidth: window.innerWidth,
    }));
    assert.ok(
        dimensions.scrollWidth <= dimensions.viewportWidth + 1,
        "Compact reports layout must not overflow the phone viewport",
    );

    await page.getByRole("button", { name: "Filters" }).click();
    await page.waitForTimeout(220);
    const modal = await page.locator(".modal-dialog").evaluate((element) => {
        const rect = element.getBoundingClientRect();
        return {
            top: rect.top,
            bottom: window.innerHeight - rect.bottom,
            centerDelta: Math.abs(
                rect.top + rect.height / 2 - window.innerHeight / 2,
            ),
        };
    });
    assert.ok(modal.top > 8 && modal.bottom > 8);
    assert.ok(
        modal.centerDelta < 80,
        "Modal must fade into the viewport center",
    );
    assert.deepEqual(errors, [], `Browser errors: ${errors.join(" | ")}`);

    await page.screenshot({
        path: path.join(artifacts, "mobile-compact-report-modal.png"),
    });
    console.log(
        "Compact mobile layout and centered modal browser test passed.",
    );
} catch (error) {
    console.error(`Preview runtime errors: ${errors.join(" | ")}`);
    console.error((await page.locator("body").innerText()).slice(0, 1200));
    throw error;
} finally {
    await browser.close();
}

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

await fs.access(path.join(projectRoot, "public", "hot"));
await fs.access(chromePath);

const browser = await chromium.launch({
    executablePath: chromePath,
    headless: true,
});
const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
const runtimeErrors = [];

page.on("pageerror", (error) => runtimeErrors.push(error.message));
page.on("console", (message) => {
    if (message.type() === "error") runtimeErrors.push(message.text());
});

try {
    const response = await page.goto(baseUrl, {
        waitUntil: "domcontentloaded",
    });
    assert.equal(
        response?.status(),
        200,
        "Laravel page should return HTTP 200",
    );
    await page
        .getByRole("heading", { name: "Lin Htut Restaurant Ledger" })
        .waitFor();

    const styled = await page.locator(".auth-card").evaluate((element) => {
        const style = getComputedStyle(element);
        return style.borderRadius !== "0px";
    });
    assert.equal(styled, true, "Vite should load the application stylesheet");
    assert.deepEqual(
        runtimeErrors,
        [],
        `Vite browser errors: ${runtimeErrors.join(" | ")}`,
    );

    console.log(
        `Vite React browser test passed at ${baseUrl}; refresh preamble detected correctly.`,
    );
} finally {
    await browser.close();
}

import assert from "node:assert/strict";
import { deriveBasePathFromBundledUrl } from "../resources/js/utils/runtime-path.js";

const cases = [
    {
        name: "Root deployment",
        scriptUrl: "https://example.com/build/assets/app-HASH.js",
        expectedBasePath: "",
        expectedApiBase: "/api",
    },
    {
        name: "Single nested folder deployment",
        scriptUrl: "https://example.com/myapp/build/assets/app-HASH.js",
        expectedBasePath: "/myapp",
        expectedApiBase: "/myapp/api",
    },
    {
        name: "Multi-level nested deployment",
        scriptUrl:
            "https://example.com/clients/tools/app/build/assets/app-HASH.js",
        expectedBasePath: "/clients/tools/app",
        expectedApiBase: "/clients/tools/app/api",
    },
];

const failures = [];

for (const item of cases) {
    const basePath = deriveBasePathFromBundledUrl(item.scriptUrl);
    const apiBase = basePath === "" ? "/api" : `${basePath}/api`;

    try {
        assert.equal(
            basePath,
            item.expectedBasePath,
            `${item.name}: base path mismatch`,
        );
        assert.equal(
            apiBase,
            item.expectedApiBase,
            `${item.name}: API base mismatch`,
        );
    } catch (error) {
        failures.push(error.message);
    }
}

if (failures.length > 0) {
    console.error("Runtime path tests failed:");
    for (const failure of failures) {
        console.error(`- ${failure}`);
    }
    process.exit(1);
}

console.log("Runtime path tests passed for root and nested deployments.");

import path from "node:path";
import { fileURLToPath } from "node:url";

import { translations } from "../resources/js/i18n/translations.js";

const __filename = fileURLToPath(import.meta.url);
const scriptDir = path.dirname(__filename);
const projectRoot = path.resolve(scriptDir, "..");
const expectedLanguages = Object.keys(translations);
const mismatchErrors = [];
const intentionallySharedValues = new Set(["language_en", "language_my"]);

if (expectedLanguages.length < 2) {
    mismatchErrors.push(
        "Expected at least two language objects in translations.js.",
    );
}

const reference = translations[expectedLanguages[0]];
const referenceKeys = Object.keys(reference || {});

for (const language of expectedLanguages.slice(1)) {
    const keys = Object.keys(translations[language] || {});
    const missing = referenceKeys.filter((key) => !keys.includes(key));
    const extra = keys.filter((key) => !referenceKeys.includes(key));

    if (missing.length > 0) {
        mismatchErrors.push(`${language} missing keys: ${missing.join(", ")}`);
    }
    if (extra.length > 0) {
        mismatchErrors.push(`${language} extra keys: ${extra.join(", ")}`);
    }

    const empty = keys.filter(
        (key) => String(translations[language][key] ?? "").trim() === "",
    );
    const unchanged = keys.filter(
        (key) =>
            !intentionallySharedValues.has(key) &&
            translations[language][key] === reference[key],
    );

    if (empty.length > 0) {
        mismatchErrors.push(`${language} empty values: ${empty.join(", ")}`);
    }
    if (unchanged.length > 0) {
        mismatchErrors.push(
            `${language} values still identical to the reference language: ${unchanged.join(", ")}`,
        );
    }
}

if (mismatchErrors.length > 0) {
    console.error("Translation mismatch detected:");
    for (const issue of mismatchErrors) {
        console.error(`- ${issue}`);
    }
    process.exit(1);
}

console.log(
    `Translations OK for ${expectedLanguages.length} languages from ${projectRoot}`,
);

import path from "node:path";
import { fileURLToPath } from "node:url";
import sharp from "sharp";

const projectRoot = path.resolve(
    path.dirname(fileURLToPath(import.meta.url)),
    "..",
);

for (const size of [192, 512]) {
    const source = path.join(projectRoot, "public", `icon-${size}.svg`);
    const destination = path.join(projectRoot, "public", `icon-${size}.png`);
    await sharp(source)
        .resize(size, size)
        .png({ compressionLevel: 9 })
        .toFile(destination);
}

console.log("Generated 192px and 512px PWA PNG icons from the SVG sources.");

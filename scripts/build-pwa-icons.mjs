import path from "node:path";
import fs from "node:fs/promises";
import { fileURLToPath } from "node:url";
import sharp from "sharp";

const projectRoot = path.resolve(
    path.dirname(fileURLToPath(import.meta.url)),
    "..",
);

const source = path.join(projectRoot, "linhtuticon.jpg");
await fs.copyFile(source, path.join(projectRoot, "public", "linhtuticon.jpg"));
const logo = await sharp(source).rotate().toBuffer();

for (const size of [48, 180, 192, 512]) {
    await sharp(logo)
        .resize(size, size, {
            fit: "contain",
            background: "#f8f5ef",
        })
        .png({ compressionLevel: 9, palette: true, quality: 90 })
        .toFile(path.join(projectRoot, "public", `icon-${size}.png`));
}

await sharp(logo)
    .resize(410, 410, { fit: "contain" })
    .extend({
        top: 51,
        bottom: 51,
        left: 51,
        right: 51,
        background: "#f8f5ef",
    })
    .png({ compressionLevel: 9, palette: true, quality: 90 })
    .toFile(path.join(projectRoot, "public", "icon-maskable-512.png"));

console.log(
    "Published the complete linhtuticon.jpg artwork and generated non-cropped PWA variants.",
);

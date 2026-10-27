import { access, cp, mkdir, rm } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const outputRoot = path.join(projectRoot, "dreamhost-dist");
const publicOutput = path.join(outputRoot, "public");
const privateOutput = path.join(outputRoot, "private");
const backendRoot = path.join(projectRoot, "backend");

await rm(outputRoot, { recursive: true, force: true });
await mkdir(publicOutput, { recursive: true });
await cp(path.join(projectRoot, "dist"), publicOutput, { recursive: true });
await cp(path.join(backendRoot, "public"), publicOutput, { recursive: true, force: true });
await mkdir(privateOutput, { recursive: true });
for (const directory of ["src", "cron", "data", "migrations"]) {
  await cp(path.join(backendRoot, directory), path.join(privateOutput, directory), { recursive: true });
}
for (const file of ["schema.sql", "config.example.php"]) {
  await cp(path.join(backendRoot, file), path.join(privateOutput, file));
}

for (const file of ["index.php", "index.html", ".htaccess", "feed.php", "sitemap.php", "api/releases.php", "api/ingest-status.php", "api/contact.php", "api/saved-transfer.php"]) {
  await access(path.join(publicOutput, file));
}

console.log(`DreamHost package ready: ${outputRoot}`);

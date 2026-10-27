import assert from "node:assert/strict";
import test from "node:test";
import { readFile } from "node:fs/promises";
import ts from "typescript";

const source = await readFile(new URL("../frontend/src/watch-availability.ts", import.meta.url), "utf8");
const js = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ESNext } }).outputText;
const { normalizeWatchAvailability, currentWatchAvailability } = await import(`data:text/javascript;base64,${Buffer.from(js).toString("base64")}`);
const availability = {
  region: "US", source: "https://www.themoviedb.org/movie/123/watch?locale=US", checkedAt: new Date().toISOString(),
  offers: { flatrate: ["Shudder"], free: [], ads: ["Tubi"], rent: ["Amazon Video"], buy: [] },
};
test("watch availability survives normalization without conflating rental or free offers", () => {
  assert.deepEqual(normalizeWatchAvailability(availability), availability);
  for (const invalid of [null, { ...availability, region: "CA" }, { ...availability, source: "javascript:alert(1)" }, { ...availability, checkedAt: "bad" }, { ...availability, offers: { ...availability.offers, rent: [null] } }]) {
    assert.equal(normalizeWatchAvailability(invalid), undefined);
  }
});
test("saved availability stops claiming current services after three days", () => {
  assert.equal(currentWatchAvailability(availability), availability);
  assert.equal(currentWatchAvailability({ ...availability, checkedAt: new Date(Date.now() - 4 * 86400000).toISOString() }), undefined);
  assert.equal(currentWatchAvailability({ ...availability, checkedAt: new Date(Date.now() + 86400000).toISOString() }), undefined);
});

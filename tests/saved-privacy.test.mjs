import assert from "node:assert/strict";
import test from "node:test";
import { readFile } from "node:fs/promises";
import ts from "typescript";

// Load the real modules with Vite's environment replaced, without a DOM or external analytics.
async function moduleUrl(path) {
  const url = new URL(path, import.meta.url);
  let source = await readFile(url, "utf8");
  for (const match of source.matchAll(/from "(\.\/[^".]+)"/g)) {
    source = source.replaceAll(`"${match[1]}"`, JSON.stringify(await moduleUrl(new URL(`${match[1]}.ts`, url).href)));
  }
  source = source.replaceAll("import.meta.env", JSON.stringify({ PROD: true, VITE_GA_MEASUREMENT_ID: "G-QATEST" }));
  return `data:text/javascript;base64,${Buffer.from(ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ESNext } }).outputText).toString("base64")}`;
}
const saved = await import(await moduleUrl("../frontend/src/saved.ts"));
const release = {
  id: "valid", title: "Valid title", date: "2026-09-10", datePrecision: "day", mediaType: "book",
  source: "https://example.com/release", sourceName: "Publisher", confidence: "official",
  creator: "Author", publisher: "Publisher", tags: ["Horror"], region: "US", synopsis: "Synopsis",
};

test("saved validation preserves valid provenance and excludes malformed entries without mutating input", () => {
  const input = { valid: release, bad: null, mismatch: { ...release, id: "other" }, badDate: { ...release, id: "badDate", date: "2026-02-30" } };
  const result = saved.normalizeSaved(input);
  assert.deepEqual(Object.keys(result.saves), ["valid"]);
  for (const [key, value] of Object.entries(release)) assert.deepEqual(result.saves.valid[key], value);
  assert.match(result.warning, /3 unreadable/);
  assert.equal(input.bad, null);
});

test("saved validation normalizes optional fields and rejects unsafe sources and broken ratings", () => {
  const result = saved.normalizeSaved({ valid: { ...release, creator: {}, tags: ["Horror", null, 5], reception: [null, { provider: "Steam", kind: "user", score: 80, scale: 100, count: 10, updatedAt: "bad date", source: "https://example.com" }] }, unsafe: { ...release, id: "unsafe", source: "javascript:alert(1)" } });
  assert.deepEqual(result.saves.valid.tags, ["Horror"]);
  assert.equal(result.saves.valid.creator, "");
  assert.deepEqual(result.saves.valid.reception, []);
  assert.equal(result.saves.unsafe, undefined);
  const undated = saved.normalizeSaved({ valid: { ...release, date: "", datePrecision: "tbd" } });
  assert.equal(undated.saves.valid.datePrecision, "tbd");
});

test("saved movies retain validated viewing availability and discard unsafe links", () => {
  const watchAvailability = {
    region: "US", source: "https://www.themoviedb.org/movie/123/watch?locale=US", checkedAt: new Date().toISOString(),
    offers: { flatrate: ["Shudder"], free: [], ads: [], rent: ["Amazon Video"], buy: [] },
  };
  const movie = { ...release, mediaType: "movie", watchAvailability };
  assert.deepEqual(saved.normalizeSaved({ valid: movie }).saves.valid.watchAvailability, watchAvailability);
  assert.equal(saved.normalizeSaved({ valid: { ...movie, watchAvailability: { ...watchAvailability, source: "javascript:alert(1)" } } }).saves.valid.watchAvailability, undefined);
});

test("storage access, invalid JSON, and quota failures are recoverable and do not overwrite data", () => {
  let raw = JSON.stringify({ valid: release, bad: null });
  let failWrite = false;
  globalThis.window = { localStorage: { getItem: () => raw, setItem: (_, value) => { if (failWrite) throw new Error("QuotaExceededError"); raw = value; } } };
  const before = raw;
  assert.equal(saved.loadSavedResult().saves.valid.title, release.title);
  assert.equal(raw, before);
  failWrite = true;
  assert.equal(saved.storeSaved({}), false);
  assert.equal(raw, before);
  failWrite = false;
  assert.equal(saved.storeSaved({ valid: release }), true);
  raw = "invalid json";
  assert.match(saved.loadSavedResult().warning, /could not be read/);
  assert.equal(raw, "invalid json");
  Object.defineProperty(globalThis.window, "localStorage", { get() { throw new Error("SecurityError"); } });
  assert.deepEqual(saved.loadSavedResult().saves, {});
  assert.equal(saved.storeSaved({}), false);
  delete globalThis.window;
});

test("incoming transfers reject malformed records before changing the local saved list", async () => {
  const transfer = await import(await moduleUrl("../frontend/src/saved-transfer.ts"));
  const originalFetch = globalThis.fetch;
  try {
    globalThis.fetch = async () => ({ ok: true, json: async () => ({ saves: { valid: release, bad: null } }) });
    await assert.rejects(transfer.claimSavedTransfer("token"), /unreadable titles/);
    globalThis.fetch = async () => ({ ok: true, json: async () => ({ saves: { valid: release } }) });
    assert.equal((await transfer.claimSavedTransfer("token")).valid.title, release.title);
  } finally { globalThis.fetch = originalFetch; }
});

test("accept, revoke, and reaccept update collection and delete only analytics cookies", async () => {
  const calls = [];
  const deletions = [];
  globalThis.window = { location: { hostname: "ghastlyguide.com" }, gtag: (...args) => calls.push(args) };
  globalThis.document = { get cookie() { return "_ga=abc; _ga_QATEST=session; functional=keep"; }, set cookie(value) { deletions.push(value); } };
  try {
    const analytics = await import(await moduleUrl("../frontend/src/analytics.ts"));
    analytics.initializeAnalytics();
    analytics.initializeAnalytics();
    assert.equal(calls.filter(args => args[0] === "config").length, 1);
    assert.equal(globalThis.window["ga-disable-G-QATEST"], false);
    analytics.revokeAnalytics();
    assert.equal(globalThis.window["ga-disable-G-QATEST"], true);
    assert.deepEqual(calls.at(-1), ["consent", "update", { analytics_storage: "denied" }]);
    assert.ok(deletions.some(cookie => cookie.startsWith("_ga=;") && cookie.includes("Domain=.ghastlyguide.com")));
    assert.ok(deletions.some(cookie => cookie.startsWith("_ga_QATEST=;")));
    assert.ok(deletions.every(cookie => !cookie.includes("functional")));
    analytics.initializeAnalytics();
    assert.equal(globalThis.window["ga-disable-G-QATEST"], false);
    assert.equal(calls.filter(args => args[0] === "config").length, 2);
    assert.ok(calls.some(args => args[0] === "consent" && args[2].analytics_storage === "granted"));
    Object.defineProperty(globalThis.document, "cookie", { get() { throw new Error("Blocked cookies"); } });
    assert.doesNotThrow(() => analytics.revokeAnalytics());
    assert.equal(globalThis.window["ga-disable-G-QATEST"], true);
  } finally { delete globalThis.window; delete globalThis.document; }
});

test("concurrent saved changes reread storage under a shared lock and preserve other tabs", async () => {
  let raw = "{}";
  let queued = Promise.resolve();
  const events = new EventTarget();
  const previousNavigator = Object.getOwnPropertyDescriptor(globalThis, "navigator");
  Object.defineProperty(globalThis, "navigator", { configurable: true, value: { locks: { request: (_name, fn) => { const next = queued.then(fn); queued = next.catch(() => {}); return next; } } } });
  globalThis.window = { localStorage: { getItem: () => raw, setItem: (_, value) => { raw = value; } }, dispatchEvent: event => events.dispatchEvent(event), addEventListener: (...args) => events.addEventListener(...args), removeEventListener: (...args) => events.removeEventListener(...args) };
  const another = { ...release, id: "another", title: "Another title" };
  let observed;
  const unsubscribe = saved.subscribeSaved(result => { observed = result; });
  try {
    await Promise.all([
      saved.updateSaved(current => ({ ...current, valid: release })),
      saved.updateSaved(current => ({ ...current, another })),
    ]);
    assert.deepEqual(Object.keys(JSON.parse(raw)).sort(), ["another", "valid"]);
    assert.deepEqual(Object.keys(observed.saves).sort(), ["another", "valid"]);
    await saved.updateSaved(current => { const next = { ...current }; delete next.valid; return next; });
    assert.deepEqual(Object.keys(JSON.parse(raw)), ["another"]);
    raw = "unreadable original";
    assert.equal((await saved.updateSaved(() => ({}))).ok, false);
    assert.equal(raw, "unreadable original");
  } finally {
    unsubscribe();
    delete globalThis.window;
    if (previousNavigator) Object.defineProperty(globalThis, "navigator", previousNavigator); else delete globalThis.navigator;
  }
});

test("saved refresh batches IDs and retains API error information", async () => {
  const releases = await import(await moduleUrl("../frontend/src/releases.ts"));
  const previousFetch = globalThis.fetch;
  const calls = [];
  globalThis.fetch = async (url, options) => { calls.push({ url: String(url), options }); return { ok: true, json: async () => ({ releases: [release] }) }; };
  try {
    const controller = new AbortController();
    const refreshed = await releases.fetchSavedReleases(Array.from({ length: 201 }, (_, i) => `release-${i + 1}`), controller.signal);
    assert.equal(calls.length, 3);
    assert.equal(refreshed.length, 3);
    assert.equal(new URL(calls[0].url, "https://example.com").searchParams.get("ids").split(",").length, 100);
    assert.equal(calls[0].options.signal, controller.signal);
    await releases.fetchSavedReleases([]);
    assert.equal(calls.length, 3);
    globalThis.fetch = async () => ({ ok: false, status: 503 });
    await assert.rejects(releases.fetchRelease("1"), /503/);
  } finally { globalThis.fetch = previousFetch; }
});

test("browse parameters preserve filters and reject unsupported sort and result counts", async () => {
  const browse = await import(await moduleUrl("../frontend/src/browse-state.ts"));
  const state = browse.readBrowseState("?q=Haunt&allDates=1&filters=video_game,invalid&sort=title&platform=PC&vr=1&shown=120");
  assert.deepEqual(state.filters, ["video_game"]);
  assert.equal(state.sort, "title");
  assert.equal(state.query, "Haunt");
  assert.equal(state.shown, 120);
  assert.equal(state.allDates, true);
  assert.equal(browse.readBrowseState("?sort=invalid&shown=999999").shown, 40);
  assert.equal(browse.readBrowseState("?sort=invalid").sort, "soonest");
});

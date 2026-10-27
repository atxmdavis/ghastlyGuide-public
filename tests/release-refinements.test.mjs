import assert from "node:assert/strict";
import test from "node:test";
import { compareReleases, highlightReleaseTags, platformValues, isVrRelease } from "../app/release-refinements.ts";

const releases = [
  { id: "z", title: "Zulu", date: "2026-09-10", datePrecision: "day" },
  { id: "a", title: "Alpha", date: "2026-10-01", datePrecision: "month" },
  { id: "b", title: "Beta", date: "2026-09-10", datePrecision: "day" },
  { id: "t", title: "Unknown", date: "", datePrecision: "tbd" },
];
test("sorting keeps date ties deterministic, undated last, and titles alphabetical across months", () => {
  const order = sort => [...releases].sort((a, b) => compareReleases(a, b, sort)).map(r => r.id);
  assert.deepEqual(order("soonest"), ["b", "z", "a", "t"]);
  assert.deepEqual(order("latest"), ["a", "b", "z", "t"]);
  assert.deepEqual(order("title"), ["a", "b", "t", "z"]);
});
test("platforms match individual values and VR requires an explicit game tag", () => {
  assert.deepEqual(platformValues({ platform: "PC (Microsoft Windows), PlayStation 5, " }), ["PC (Microsoft Windows)", "PlayStation 5"]);
  assert.deepEqual(platformValues({}), []);
  assert.equal(isVrRelease({ mediaType: "video_game", tags: ["VR"] }), true);
  assert.equal(isVrRelease({ mediaType: "video_game", tags: ["Survival"] }), false);
  assert.equal(isVrRelease({ mediaType: "movie", tags: ["VR"] }), false);
});
test("cards only highlight 4K, reissue, and VR tags", () => {
  assert.deepEqual(highlightReleaseTags({ mediaType: "movie", tags: ["Horror", "4K UHD", "Re-release", "Blu-ray"] }), ["4K", "Reissue"]);
  assert.deepEqual(highlightReleaseTags({ mediaType: "movie", tags: ["Remastered"] }), ["Reissue"]);
  assert.deepEqual(highlightReleaseTags({ mediaType: "movie", tags: ["Horror", "Physical debut"] }), []);
  assert.deepEqual(highlightReleaseTags({ mediaType: "video_game", tags: ["Horror", "VR", "4K UHD"] }), ["VR"]);
  assert.deepEqual(highlightReleaseTags({ mediaType: "book", tags: ["4K UHD", "Re-release", "VR"] }), []);
});

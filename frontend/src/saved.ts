import { mediaTypes, type Release, type ReceptionRating } from "./types";
import { normalizeWatchAvailability } from "./watch-availability";

export const STORAGE_KEY = "ghastly-guide:saved:v1";
export type SavedState = Record<string, Release>;
export type SavedResult = { saves: SavedState; warning: string };

function record(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === "object" && !Array.isArray(value);
}
function text(value: unknown): string { return typeof value === "string" ? value : ""; }
function webUrl(value: unknown): string {
  if (typeof value !== "string") return "";
  try { return ["http:", "https:"].includes(new URL(value).protocol) ? value : ""; } catch { return ""; }
}
function validDate(value: string): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
  const date = new Date(`${value}T12:00:00Z`);
  return Number.isFinite(date.getTime()) && date.toISOString().slice(0, 10) === value;
}
function rating(value: unknown): value is ReceptionRating {
  return record(value) && ["Steam", "TMDB"].includes(text(value.provider))
    && ["user", "critic"].includes(text(value.kind))
    && typeof value.score === "number" && Number.isFinite(value.score)
    && typeof value.scale === "number" && Number.isFinite(value.scale) && value.scale > 0
    && typeof value.count === "number" && Number.isFinite(value.count) && value.count >= 0
    && typeof value.updatedAt === "string" && Number.isFinite(Date.parse(value.updatedAt))
    && Boolean(webUrl(value.source)) && (value.label == null || typeof value.label === "string");
}

export function normalizeSaved(value: unknown): SavedResult {
  if (!record(value)) return { saves: {}, warning: "Saved titles could not be read. The stored data has not been changed." };
  const entries: [string, Release][] = [];
  let skipped = 0;
  for (const [key, item] of Object.entries(value)) {
    if (!record(item) || !key || ["__proto__", "constructor", "prototype"].includes(key) || key !== item.id || !text(item.title).trim()
      || !mediaTypes.includes(item.mediaType as Release["mediaType"])
      || !["day", "month", "year", "tbd"].includes(text(item.datePrecision))
      || !(validDate(text(item.date)) || (item.datePrecision === "tbd" && item.date === ""))
      || !["official", "partner", "database", "editorial"].includes(text(item.confidence))
      || !webUrl(item.source)) {
      skipped++;
      continue;
    }
    entries.push([key, {
      id: key, title: text(item.title), mediaType: item.mediaType as Release["mediaType"],
      date: text(item.date), datePrecision: item.datePrecision as Release["datePrecision"],
      creator: text(item.creator), publisher: text(item.publisher), platform: text(item.platform), region: text(item.region),
      tags: Array.isArray(item.tags) ? item.tags.filter((tag): tag is string => typeof tag === "string") : [],
      source: webUrl(item.source), sourceName: text(item.sourceName), confidence: item.confidence as Release["confidence"],
      artwork: typeof item.artwork === "string" && /^\/(?!\/)/.test(item.artwork) ? item.artwork : webUrl(item.artwork), trailer: webUrl(item.trailer), synopsis: text(item.synopsis),
      steamUrl: item.mediaType === "video_game" && typeof item.steamUrl === "string" && /^https:\/\/store\.steampowered\.com\/app\/[0-9]+\/$/.test(item.steamUrl) ? item.steamUrl : undefined,
      watchAvailability: item.mediaType === "movie" ? normalizeWatchAvailability(item.watchAvailability) : undefined,
      verifiedAt: typeof item.verifiedAt === "string" && Number.isFinite(Date.parse(item.verifiedAt)) ? item.verifiedAt : undefined,
      reception: Array.isArray(item.reception) ? item.reception.filter(rating) : [],
    }]);
  }
  return {
    saves: Object.fromEntries(entries),
    warning: skipped ? `${skipped} unreadable saved ${skipped === 1 ? "title was" : "titles were"} skipped. Valid titles are still available; stored data has not been changed.` : "",
  };
}

export function loadSavedResult(): SavedResult {
  if (typeof window === "undefined") return { saves: {}, warning: "" };
  try {
    return normalizeSaved(JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? "{}"));
  } catch {
    return { saves: {}, warning: "Saved titles could not be read. Allow browser storage and try again. Stored data has not been changed." };
  }
}
export function loadSaved(): SavedState { return loadSavedResult().saves; }

export function storeSaved(value: SavedState): boolean {
  try {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(value));
    return true;
  } catch {
    return false;
  }
}
export function mergeSaved(current: SavedState, incoming: SavedState): SavedState {
  return { ...current, ...incoming };
}

// Serialize read-modify-write operations across tabs where Web Locks are available.
export async function updateSaved(update: (current: SavedState) => SavedState): Promise<SavedResult & { ok: boolean }> {
  const commit = () => {
    const current = loadSavedResult();
    if (current.warning) return { ...current, ok: false };
    const next = update(current.saves);
    const normalized = normalizeSaved(next);
    if (normalized.warning) return { ...current, ok: false, warning: "The updated saved list could not be validated. Your stored titles have not been changed." };
    if (!storeSaved(normalized.saves)) return { ...current, ok: false, warning: "Changes could not be saved on this device. Allow browser storage or free space, then try again. Your saved list has not changed." };
    window.dispatchEvent(new Event("ghastly-saved-change"));
    return { saves: normalized.saves, warning: "", ok: true };
  };
  return typeof navigator !== "undefined" && navigator.locks
    ? navigator.locks.request(STORAGE_KEY, commit)
    : commit();
}

export function subscribeSaved(onChange: (result: SavedResult) => void): () => void {
  const change = () => onChange(loadSavedResult());
  const storage = (event: StorageEvent) => { if (event.key === STORAGE_KEY || event.key === null) change(); };
  window.addEventListener("storage", storage);
  window.addEventListener("ghastly-saved-change", change);
  return () => {
    window.removeEventListener("storage", storage);
    window.removeEventListener("ghastly-saved-change", change);
  };
}

import { releaseFilters, type ReleaseFilter } from "./types";
import type { ReleaseSort } from "./release-refinements";

const STORAGE_KEY = "ghastly-guide:browse-return:v1";

export function readBrowseState(search = window.location.search) {
  const params = new URLSearchParams(search);
  const filters = params.has("filters") ? params.get("filters")!.split(",").filter((id): id is ReleaseFilter => releaseFilters.some(filter => filter.id === id)) : null;
  const sort: ReleaseSort = params.get("sort") === "title" ? "title" : params.get("sort") === "latest" ? "latest" : "soonest";
  const shown = Number(params.get("shown"));
  return { params, filters, sort, query: (params.get("q") ?? "").slice(0, 100), allDates: params.get("allDates") === "1", platform: (params.get("platform") ?? "").slice(0, 255), vr: params.get("vr") === "1", shown: Number.isInteger(shown) && shown >= 40 && shown <= 10000 ? shown : 40 };
}

export function rememberBrowse(): void {
  try { window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify({ href: `${window.location.pathname}${window.location.search}${window.location.hash}`, scroll: window.scrollY })); } catch { /* Browsing still works without storage. */ }
}

export function browseReturn(): { href: string; scroll: number } {
  try {
    const stored = JSON.parse(window.sessionStorage.getItem(STORAGE_KEY) ?? "null");
    if (typeof stored?.href === "string" && /^\/(?:\?|#|$|(?:movies|shows|games|tabletop|comics|books)(?:[?#]|$)|calendar\/20\d{2}\/\d{2}(?:[?#]|$))/.test(stored.href) && Number.isFinite(stored.scroll)) return stored;
  } catch { /* Use the calendar when there is no previous browsing context. */ }
  return { href: "/#full-calendar", scroll: 0 };
}

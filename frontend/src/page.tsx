import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { browseReturn, readBrowseState, rememberBrowse } from "./browse-state";
import { canAddToCalendar, downloadSavedCalendar } from "./calendar-event";
import { addDays, datePresets, formatDateRange, isoToday, monthKey, monthLabel, presetForRange, rangeForPreset, type DatePreset } from "./date";
import { fetchReleases, fetchSavedReleases, searchReleases } from "./releases";
import { compareReleases, isVrRelease, platformValues, type ReleaseSort } from "./release-refinements";
import OrnateDivider from "./ornate-divider";
import ReleaseCard from "./release-card";
import { loadSavedResult, mergeSaved, subscribeSaved, updateSaved, type SavedState } from "./saved";
import SavedTransferModal from "./saved-transfer-modal";
import { claimSavedTransferOnce, clearCopyTokenFromUrl, pendingCopyToken } from "./saved-transfer";
import SiteFooter from "./site-footer";
import SiteHeader from "./site-header";
import { releaseFilters, releaseMatchesFilter, type Release, type ReleaseFilter } from "./types";

type LoadState = "loading" | "ready" | "error";
type SearchState = "idle" | LoadState;
const resultBatchSize = 40;

const navFilterMap: Record<string, ReleaseFilter[]> = {
  movies: ["movie_theatrical", "movie_home"],
  shows: ["show"],
  games: ["video_game"],
  comics: ["comic"],
  books: ["book"],
  tabletop: ["tabletop_game"],
};

const routeFilterMap: Record<string, ReleaseFilter[]> = {
  "/movies": navFilterMap.movies,
  "/shows": navFilterMap.shows,
  "/games": navFilterMap.games,
  "/tabletop": navFilterMap.tabletop,
  "/comics": navFilterMap.comics,
  "/books": navFilterMap.books,
};

function currentPath(): string {
  return window.location.pathname.replace(/\/+$/, "") || "/";
}

function initialFilters(): ReleaseFilter[] {
  const stored = readBrowseState().filters;
  if (stored !== null) return stored;
  if (routeFilterMap[currentPath()]) return routeFilterMap[currentPath()];
  const type = new URLSearchParams(window.location.search).get("type");
  return type ? navFilterMap[type] ?? [] : [];
}

function arrivingAtCalendar(): boolean {
  const params = new URLSearchParams(window.location.search);
  return currentPath() !== "/" || params.get("saved") === "1" || params.get("range") === "week" || window.location.hash === "#full-calendar";
}

function monthRoute(): { from: string; to: string; label: string; previous: string; next: string } | null {
  const match = currentPath().match(/^\/calendar\/(20\d{2})\/(0[1-9]|1[0-2])$/);
  if (!match) return null;
  const date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, 1));
  const from = `${match[1]}-${match[2]}-01`;
  const end = new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth() + 1, 0));
  const adjacent = (offset: number) => {
    const value = new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth() + offset, 1));
    return `/calendar/${value.getUTCFullYear()}/${String(value.getUTCMonth() + 1).padStart(2, "0")}`;
  };
  return {
    from,
    to: `${end.getUTCFullYear()}-${String(end.getUTCMonth() + 1).padStart(2, "0")}-${String(end.getUTCDate()).padStart(2, "0")}`,
    label: new Intl.DateTimeFormat("en-US", { month: "long", year: "numeric", timeZone: "UTC" }).format(date),
    previous: adjacent(-1),
    next: adjacent(1),
  };
}

function routeHeading(): string | null {
  return ({
    "/movies": "Upcoming Horror Movies",
    "/shows": "Upcoming Horror Shows",
    "/games": "Upcoming Horror Video Games",
    "/tabletop": "Upcoming Horror Tabletop Games",
    "/comics": "Upcoming Horror Comics",
    "/books": "Upcoming Horror Books",
  } as Record<string, string>)[currentPath()] ?? null;
}

export default function Home() {
  const [browse] = useState(readBrowseState);
  const routedMonth = useMemo(monthRoute, []);
  const initialRange = useMemo(() => {
    const { params } = readBrowseState();
    const from = params.get("from") ?? "";
    const to = params.get("to") ?? "";
    const valid = (value: string) => /^\d{4}-\d{2}-\d{2}$/.test(value) && Number.isFinite(Date.parse(`${value}T12:00:00Z`)) && new Date(`${value}T12:00:00Z`).toISOString().slice(0, 10) === value;
    if (valid(from) && valid(to) && from <= to) return { from, to };
    if (routedMonth) return { from: routedMonth.from, to: routedMonth.to };
    const today = isoToday();
    if (new URLSearchParams(window.location.search).get("range") === "week") {
      return { from: today, to: addDays(today, 6) };
    }
    if (routeHeading()) return { from: `${today.slice(0, 8)}01`, to: addDays(today, 365) };
    return rangeForPreset("months3", today);
  }, [routedMonth]);
  const [from, setFrom] = useState(initialRange.from);
  const [to, setTo] = useState(initialRange.to);
  const [releases, setReleases] = useState<Release[]>([]);
  const [loadState, setLoadState] = useState<LoadState>("loading");
  const [query, setQuery] = useState(browse.query);
  const [searchAllDates, setSearchAllDates] = useState(browse.allDates);
  const [searchResults, setSearchResults] = useState<Release[]>([]);
  const [searchState, setSearchState] = useState<SearchState>("idle");
  const [searchTruncated, setSearchTruncated] = useState(false);
  const [searchRevision, setSearchRevision] = useState(0);
  const [sort, setSort] = useState<ReleaseSort>(browse.sort);
  const [platformFilter, setPlatformFilter] = useState(browse.platform);
  const [vrOnly, setVrOnly] = useState(browse.vr);
  const [refineOpen, setRefineOpen] = useState(false);
  const [selectedFilters, setSelectedFilters] = useState<ReleaseFilter[]>(initialFilters);
  const [initialSaved] = useState(loadSavedResult);
  const [saved, setSaved] = useState<SavedState>(initialSaved.saves);
  const [savedError, setSavedError] = useState(initialSaved.warning);
  const [pendingIncoming, setPendingIncoming] = useState<SavedState | null>(null);
  const [savedOnly] = useState(() => new URLSearchParams(window.location.search).get("saved") === "1");
  const [showTransfer, setShowTransfer] = useState(false);
  const [copyStatus, setCopyStatus] = useState("");
  const [clearSavedArmed, setClearSavedArmed] = useState(false);
  const [lastUpdated, setLastUpdated] = useState<string | null>(null);
  const [showHero] = useState(() => !arrivingAtCalendar());
  const [customRange, setCustomRange] = useState(() => !presetForRange(initialRange.from, initialRange.to));
  const [feedCopied, setFeedCopied] = useState(false);
  const [visibleResultCount, setVisibleResultCount] = useState(browse.shown);
  const loadControllerRef = useRef<AbortController | null>(null);
  const resultsSentinelRef = useRef<HTMLDivElement>(null);
  const activePreset = customRange ? "" : presetForRange(from, to);

  const load = useCallback(async (nextFrom: string, nextTo: string) => {
    loadControllerRef.current?.abort();
    const controller = new AbortController();
    loadControllerRef.current = controller;
    setLoadState("loading");
    try {
      const payload = await fetchReleases(nextFrom, nextTo, controller.signal);
      if (controller.signal.aborted) return;
      setReleases(payload.releases ?? []);
      setLastUpdated(payload.updatedAt ?? null);
      setLoadState("ready");
    } catch {
      if (controller.signal.aborted) return;
      setLoadState("error");
    }
  }, []);

  useEffect(() => {
    if (savedOnly) return;
    void load(from, to);
    return () => loadControllerRef.current?.abort();
  }, [from, load, savedOnly, to]);

  const searchTerm = query.trim();
  const wholeDatabaseSearch = !savedOnly && searchAllDates && searchTerm.length >= 2;

  useEffect(() => {
    if (!wholeDatabaseSearch) {
      setSearchResults([]);
      setSearchState("idle");
      setSearchTruncated(false);
      return;
    }
    const controller = new AbortController();
    setSearchResults([]);
    setSearchState("loading");
    setSearchTruncated(false);
    const timeout = window.setTimeout(async () => {
      try {
        const payload = await searchReleases(searchTerm, controller.signal);
        setSearchResults(payload.releases ?? []);
        setSearchTruncated(payload.truncated === true);
        setLastUpdated(payload.updatedAt ?? null);
        setSearchState("ready");
      } catch (error) {
        if (error instanceof DOMException && error.name === "AbortError") return;
        setSearchState("error");
      }
    }, 300);
    return () => {
      window.clearTimeout(timeout);
      controller.abort();
    };
  }, [searchRevision, searchTerm, wholeDatabaseSearch]);

  function toggleFilter(filter: ReleaseFilter) {
    setPlatformFilter("");
    setVrOnly(false);
    setSelectedFilters(current => current.includes(filter) ? current.filter(item => item !== filter) : [...current, filter]);
  }

  const commitSaved = useCallback(async (update: (current: SavedState) => SavedState): Promise<boolean> => {
    const result = await updateSaved(update);
    setSaved(result.saves);
    setSavedError(result.warning);
    return result.ok;
  }, []);

  async function toggleSaved(release: Release): Promise<boolean> {
    return commitSaved(current => {
      const next = { ...current };
      if (next[release.id]) delete next[release.id]; else next[release.id] = release;
      return next;
    });
  }

  function reloadSaved() {
    const result = loadSavedResult();
    setSaved(result.saves);
    setSavedError(result.warning);
  }

  async function clearAllSaved() {
    if (!clearSavedArmed) {
      setClearSavedArmed(true);
      return;
    }
    await commitSaved(() => ({}));
    setClearSavedArmed(false);
  }

  const saveIncoming = useCallback(async (incoming: SavedState) => {
    if (!await commitSaved(current => mergeSaved(current, incoming))) {
      setPendingIncoming(incoming);
      setCopyStatus("Copy received, but this browser could not save it. Allow storage and retry before leaving this page.");
      return;
    }
    setPendingIncoming(null);
    const count = Object.keys(incoming).length;
    setCopyStatus(`Copied ${count} ${count === 1 ? "title" : "titles"} onto this device.`);
  }, [commitSaved]);

  useEffect(() => subscribeSaved(result => {
    setSaved(result.saves);
    setSavedError(result.warning);
  }), []);

  const [refreshingSaved, setRefreshingSaved] = useState(savedOnly);
  const [refreshStatus, setRefreshStatus] = useState("");
  useEffect(() => {
    if (!savedOnly) return;
    const controller = new AbortController();
    void fetchSavedReleases(Object.keys(initialSaved.saves), controller.signal).then(async fresh => {
      if (controller.signal.aborted) return;
      if (fresh.length) await commitSaved(current => {
        const next = { ...current };
        for (const release of fresh) if (next[release.id]) next[release.id] = release;
        return next;
      });
      if (!controller.signal.aborted) {
        setRefreshStatus("Available release dates and details have been refreshed. Unavailable titles keep their last known details.");
        setRefreshingSaved(false);
      }
    }).catch(() => {
      if (controller.signal.aborted) return;
      setRefreshStatus("Current release details could not be checked. Your saves and calendar downloads use the last known dates.");
      setRefreshingSaved(false);
    });
    return () => controller.abort();
  }, [commitSaved, initialSaved.saves, savedOnly]);

  function applyRange(nextFrom: string, nextTo: string, custom = true) {
    if (!nextFrom || !nextTo || nextFrom > nextTo) return;
    setCustomRange(custom);
    setFrom(nextFrom);
    setTo(nextTo);
  }

  function applyQuickFilter(preset: DatePreset) {
    const next = rangeForPreset(preset);
    applyRange(next.from, next.to, false);
  }

  async function copyRssFeed() {
    const url = `${window.location.origin}/feed.xml`;
    try {
      await navigator.clipboard.writeText(url);
      setFeedCopied(true);
      window.setTimeout(() => setFeedCopied(false), 2200);
    } catch {
      window.prompt("Copy this RSS feed URL into a reader app", url);
    }
  }

  const pool = savedOnly ? Object.values(saved) : wholeDatabaseSearch ? searchResults : releases;
  const savedCount = Object.keys(saved).length;
  const savedCalendarCount = Object.values(saved).filter(canAddToCalendar).length;
  const savedCalendarHint = savedCalendarCount
    ? "Download one calendar file of saved titles that have a specific date."
    : savedCount
      ? "This stays off until a saved title has a specific date rather than a month or TBD."
      : "Save a title with a specific date to download a calendar file.";
  const showSavedBrowseTools = !savedOnly || savedCount > 50;
  const matching = pool
    .filter(release => !showSavedBrowseTools || !selectedFilters.length || selectedFilters.some(filter => releaseMatchesFilter(release, filter)))
    .filter(release => !showSavedBrowseTools || wholeDatabaseSearch || `${release.title} ${release.creator} ${release.publisher} ${release.tags.join(" ")}`.toLowerCase().includes(query.trim().toLowerCase()));
  const gameContext = selectedFilters.length === 1 && selectedFilters[0] === "video_game";
  const movieContext = selectedFilters.length > 0 && selectedFilters.every(filter => filter === "movie_home" || filter === "movie_theatrical");
  const platformOptions = [...new Set(matching.flatMap(platformValues).concat(platformFilter ? [platformFilter] : []))].sort();
  const filtered = matching
    .filter(release => !showSavedBrowseTools || !platformFilter || platformValues(release).includes(platformFilter))
    .filter(release => !showSavedBrowseTools || !vrOnly || isVrRelease(release))
    .sort((a, b) => compareReleases(a, b, sort));
  const visibleReleases = filtered.slice(0, visibleResultCount);
  const grouped = visibleReleases.reduce<Record<string, Release[]>>((result, release) => {
    (result[monthKey(release.date)] ??= []).push(release);
    return result;
  }, {});
  const monthGroups: [string, Release[]][] = sort === "title" ? [["titles", visibleReleases]] : Object.entries(grouped);
  const weekView = new URLSearchParams(window.location.search).get("range") === "week";
  const calendarHeading = savedOnly ? "Saved Releases" : routedMonth ? `${routedMonth.label} Horror Releases` : routeHeading() ?? (weekView ? "This Week's Haunts" : "Ghastly Calendar");
  const activeLoadState = savedOnly ? "ready" : wholeDatabaseSearch ? searchState : loadState;
  const CalendarHeading = showHero ? "h2" : "h1";
  const MonthHeading = showHero ? "h3" : "h2";
  const showSkeleton = activeLoadState === "loading" && !savedOnly && pool.length === 0;
  const hasMoreResults = visibleReleases.length < filtered.length;

  const filterSignature = JSON.stringify([from, query, selectedFilters, to, sort, platformFilter, vrOnly, searchAllDates]);
  const previousFilters = useRef(filterSignature);
  useEffect(() => {
    if (previousFilters.current !== filterSignature) setVisibleResultCount(resultBatchSize);
    previousFilters.current = filterSignature;
  }, [filterSignature]);

  useEffect(() => {
    const url = new URL(window.location.href);
    const values: Record<string, string> = { from, to, q: query, allDates: searchAllDates ? "1" : "", filters: selectedFilters.join(",") || "all", sort: sort === "soonest" ? "" : sort, platform: platformFilter, vr: vrOnly ? "1" : "", shown: visibleResultCount > resultBatchSize ? String(visibleResultCount) : "" };
    for (const [key, value] of Object.entries(values)) {
      if (value) url.searchParams.set(key, value); else url.searchParams.delete(key);
    }
    window.history.replaceState({}, "", `${url.pathname}${url.search}${url.hash}`);
  }, [from, to, query, searchAllDates, selectedFilters, sort, platformFilter, vrOnly, visibleResultCount]);

  useEffect(() => {
    const remember = (event: MouseEvent) => {
      if ((event.target as Element)?.closest("a[href^='/releases/']")) rememberBrowse();
    };
    document.addEventListener("click", remember, true);
    return () => document.removeEventListener("click", remember, true);
  }, []);

  const restoredScroll = useRef(false);
  useEffect(() => {
    if (restoredScroll.current || activeLoadState !== "ready") return;
    const previous = browseReturn();
    const current = `${window.location.pathname}${window.location.search}${window.location.hash}`;
    if (previous.href !== current || !previous.scroll) { restoredScroll.current = true; return; }
    const frame = window.requestAnimationFrame(() => { restoredScroll.current = true; window.scrollTo({ top: previous.scroll, behavior: "instant" }); });
    return () => window.cancelAnimationFrame(frame);
  }, [activeLoadState]);

  useEffect(() => {
    if (!savedOnly) return;
    const token = pendingCopyToken();
    if (!token) return;
    let cancelled = false;
    setCopyStatus("Copying saved titles onto this device…");
    void claimSavedTransferOnce(token).then(incoming => {
      if (cancelled) return;
      clearCopyTokenFromUrl();
      saveIncoming(incoming);
    }).catch(error => {
      if (cancelled) return;
      clearCopyTokenFromUrl();
      setCopyStatus(error instanceof Error ? error.message : "That copy link expired or was already used.");
    });
    return () => { cancelled = true; };
  }, [savedOnly, saveIncoming]);

  useEffect(() => {
    if (savedCount === 0) setClearSavedArmed(false);
  }, [savedCount]);

  useEffect(() => {
    if (!clearSavedArmed) return;
    const timer = window.setTimeout(() => setClearSavedArmed(false), 6000);
    const onKey = (event: KeyboardEvent) => { if (event.key === "Escape") setClearSavedArmed(false); };
    window.addEventListener("keydown", onKey);
    return () => {
      window.clearTimeout(timer);
      window.removeEventListener("keydown", onKey);
    };
  }, [clearSavedArmed]);

  useEffect(() => {
    const node = resultsSentinelRef.current;
    if (!node || !hasMoreResults || activeLoadState !== "ready") return;
    const observer = new IntersectionObserver(entries => {
      if (entries.some(entry => entry.isIntersecting)) {
        setVisibleResultCount(current => Math.min(current + resultBatchSize, filtered.length));
      }
    }, { rootMargin: "0px 0px 720px 0px" });
    observer.observe(node);
    return () => observer.disconnect();
  }, [activeLoadState, filtered.length, hasMoreResults]);

  return (
    <>
      <main id="main-content" tabIndex={-1}>
      {showHero ? <section className="hero">
        <div className="hero__curtain hero__curtain--left" aria-hidden="true" />
        <div className="hero__curtain hero__curtain--right" aria-hidden="true" />
        <span className="hero__frame-corner hero__frame-corner--tl" aria-hidden="true" />
        <span className="hero__frame-corner hero__frame-corner--tr" aria-hidden="true" />
        <span className="hero__frame-corner hero__frame-corner--bl" aria-hidden="true" />
        <span className="hero__frame-corner hero__frame-corner--br" aria-hidden="true" />
        <div className="hero__inner">
          <div className="hero__content">
            <h1>Find What<br /><em>Haunts You</em><br />Next</h1>
            <p className="hero__lede">Discover new and upcoming horror across movies, video games, shows, comics, books, and tabletop games.</p>
            <div className="hero__actions">
              <a className="button button--primary" href="#full-calendar">Explore what’s new</a>
            </div>
          </div>
          <img className="hero__logo" src="/assets/ghastly-guide-logo.webp" width="1169" height="1345" alt="Ghastly Guide" />
        </div>
        <OrnateDivider className="hero__ornament" />
      </section> : null}
      <section className={showHero ? "discovery discovery--after-hero" : "discovery discovery--lead"} id="full-calendar">
        <SiteHeader savedCount={savedCount} belowHero={showHero} heading={<>
          <p className="eyebrow">{savedOnly ? "Kept on this device" : "Upcoming horror across media"}</p>
          <CalendarHeading>{calendarHeading}</CalendarHeading>
        </>} />
        <div className="discovery__inner">
        {savedOnly ? <div className="section-heading">
          <div>
              <p className="section-heading__note">Saves live in this browser on this device—no account, login, or profile to keep up with. Keep your saves here to reference, and copy them to another device to keep up. Lists are not synced, so a change on one device will not update the other until you copy them over again.</p>
              <p className="section-heading__note">Download calendar makes one file you can import into Apple Calendar, Google Calendar, or Outlook. Only titles with a specific day are included, so the button stays off until you have at least one of those. Month-only and TBD dates are left out.</p>
              {refreshStatus || refreshingSaved ? <p className="section-heading__note" role="status">{refreshingSaved ? "Checking saved release dates…" : refreshStatus}</p> : null}
              {copyStatus ? <p className="section-heading__note" role="status">{copyStatus}</p> : null}
              {pendingIncoming ? <button type="button" className="button button--secondary" onClick={() => saveIncoming(pendingIncoming)}>Retry saving copied titles</button> : null}
          </div>
          <div className="section-heading__actions">
            <button type="button" className="button button--secondary button--hint" disabled={refreshingSaved || savedCalendarCount === 0} onClick={() => downloadSavedCalendar(Object.values(saved))} aria-label={`Download calendar. ${savedCalendarHint}`}>Download calendar</button>
            <button type="button" className="button button--secondary" disabled={savedCount === 0} onClick={() => setShowTransfer(true)}>Copy to another device</button>
          </div>
        </div> : null}
        {showTransfer ? <SavedTransferModal saves={saved} onClose={() => setShowTransfer(false)} /> : null}

          {savedError ? <div className="notice saved-notice" role="alert"><p>{savedError}</p><button type="button" className="button button--secondary" onClick={reloadSaved}>Reload saved titles</button></div> : null}
          <div className="calendar-toolbar">
          <div className="toolbar-meta">
            {!savedOnly ? <div className="date-range" role="group" aria-label="Release date range">
              <label className="date-range__preset">Quick filter
                <select
                  value={customRange ? "custom" : activePreset}
                  aria-label="Quick date filter"
                  onChange={event => {
                    const value = event.target.value;
                    if (value === "custom") {
                      setCustomRange(true);
                      return;
                    }
                    if (datePresets.some(option => option.id === value)) applyQuickFilter(value as DatePreset);
                  }}
                >
                  {datePresets.map(option => <option key={option.id} value={option.id}>{option.label}</option>)}
                  <option value="custom">Custom</option>
                </select>
              </label>
              {!customRange ? <p className="date-range__summary">{formatDateRange(from, to)}</p> : null}
              {customRange ? <>
                <label>From<input type="date" value={from} max={to} onChange={event => applyRange(event.target.value, to)} /></label>
                <span aria-hidden="true">→</span>
                <label>Through<input type="date" value={to} min={from} onChange={event => applyRange(from, event.target.value)} /></label>
              </> : null}
            </div> : null}
            <div className="toolbar-status" aria-live="polite"><strong>{showSkeleton ? "—" : filtered.length}</strong><span>{filtered.length === 1 ? "release" : "releases"}</span>{savedOnly ? <button type="button" className={clearSavedArmed ? "toolbar-status__clear is-armed" : "toolbar-status__clear"} disabled={savedCount === 0} onClick={clearAllSaved} aria-label={clearSavedArmed ? "Confirm remove all saved titles from this device" : "Remove all saved titles from this device"}>{clearSavedArmed ? "Confirm remove all" : "Remove all"}</button> : <small>{lastUpdated ? `Updated ${new Intl.DateTimeFormat("en-US", { month: "short", day: "numeric", hour: "numeric", minute: "2-digit" }).format(new Date(lastUpdated))}` : activeLoadState === "loading" ? "Checking sources…" : "Source status pending"}</small>}</div>
          </div>
          {showSavedBrowseTools ? <div className="search-control">
            <label className="search-field" id="release-search">
              <span className="sr-only">Search releases</span>
              <input type="search" value={query} maxLength={100} onChange={event => { setQuery(event.target.value); if (!event.target.value.trim()) setSearchAllDates(false); }} placeholder="Search title, creator, publisher…" />
              {query ? <button type="button" className="search-field__clear" onClick={() => { setQuery(""); setSearchAllDates(false); }}>Clear</button> : null}
            </label>
            {searchTerm ? <div className="search-scope" aria-live="polite">
              <span>{searchAllDates && searchTerm.length < 2 ? "Type one more character to search all release dates." : wholeDatabaseSearch ? `Searching all release dates${searchTruncated ? " · First 200 matches shown" : ""}` : savedOnly ? "Searching saved releases" : `Searching ${formatDateRange(from, to)}`}</span>
              {searchTerm.length >= 2 && !savedOnly ? <button type="button" onClick={() => setSearchAllDates(current => !current)}>{wholeDatabaseSearch ? "Limit to selected dates" : "Search all dates"}</button> : null}
            </div> : null}
          </div> : null}
          <div className="refine-control">
          {showSavedBrowseTools ? <div className="type-filters-wrap">
            <div className="type-filters" role="group" aria-label="Filter releases">
              <button type="button" className={!selectedFilters.length ? "is-active" : ""} aria-pressed={!selectedFilters.length} onClick={() => { setSelectedFilters([]); setPlatformFilter(""); setVrOnly(false); }}>All</button>
              {releaseFilters.map(filter => <button key={filter.id} type="button" className={selectedFilters.includes(filter.id) ? "is-active" : ""} aria-pressed={selectedFilters.includes(filter.id)} onClick={() => toggleFilter(filter.id)}>{filter.label}</button>)}
            </div>
          </div> : null}
            <button type="button" className={refineOpen || sort !== "soonest" || platformFilter || vrOnly ? "refine-toggle is-active" : "refine-toggle"} aria-expanded={refineOpen} aria-controls="release-refinements" onClick={() => setRefineOpen(current => !current)}>Sort{platformFilter || vrOnly ? ` (${Number(Boolean(platformFilter)) + Number(vrOnly)})` : ""}</button>
            {sort !== "soonest" || platformFilter || vrOnly ? <div className="refine-chips" aria-label="Active refinements">
              {sort !== "soonest" ? <button type="button" onClick={() => setSort("soonest")} aria-label="Reset sorting to soonest first">{sort === "latest" ? "Latest first" : "Title A–Z"} ×</button> : null}
              {platformFilter ? <button type="button" onClick={() => setPlatformFilter("")} aria-label={`Remove ${platformFilter} filter`}>{platformFilter} ×</button> : null}
              {vrOnly ? <button type="button" onClick={() => setVrOnly(false)} aria-label="Remove VR filter">VR only ×</button> : null}
              <button type="button" onClick={() => { setSort("soonest"); setPlatformFilter(""); setVrOnly(false); }}>Clear sort</button>
            </div> : null}
            <div className="refine-panel" id="release-refinements" hidden={!refineOpen}>
              <label>Sort by<select value={sort} onChange={event => setSort(event.target.value as ReleaseSort)}>
                <option value="soonest">Release date: soonest first</option>
                <option value="latest">Release date: latest first</option>
                <option value="title">Title: A–Z</option>
              </select></label>
              {showSavedBrowseTools && (gameContext || movieContext) ? <>
                <label>{gameContext ? "Platform" : "Release format"}<select value={platformFilter} onChange={event => setPlatformFilter(event.target.value)}>
                  <option value="">{gameContext ? "All platforms" : "All release formats"}</option>
                  {platformOptions.map(platform => <option key={platform} value={platform}>{platform}</option>)}
                </select></label>
                {gameContext ? <label className="refine-checkbox"><input type="checkbox" checked={vrOnly} onChange={event => setVrOnly(event.target.checked)} />VR titles only</label> : null}
              </> : showSavedBrowseTools ? <p>Select Video games or a movie category for more filters.</p> : null}
            </div>
          </div>
          </div>

        {routedMonth ? <nav className="calendar-month-nav" aria-label="Calendar months">
          <a href={routedMonth.previous}>← Previous month</a>
          <a href={routedMonth.next}>Next month →</a>
        </nav> : null}

        {activeLoadState === "error" && !savedOnly ? <div className="notice" role="alert"><strong>The crypt is quiet.</strong><p>{wholeDatabaseSearch ? "Search is temporarily unavailable." : "Release data is temporarily unavailable."}</p><button type="button" className="button button--primary" onClick={() => wholeDatabaseSearch ? setSearchRevision(current => current + 1) : void load(from, to)}>Try again</button></div> : null}
        {showSkeleton ? <div className="release-grid release-grid--skeleton" aria-hidden="true">{Array.from({ length: 6 }, (_, index) => <div className="release-card release-card--skeleton" key={index} />)}</div> : null}
        {activeLoadState === "ready" && filtered.length === 0 ? <div className="notice"><strong>{savedOnly ? savedCount ? "No saved releases match these filters." : "Nothing saved yet." : wholeDatabaseSearch ? "No releases match that search." : "No verified releases in this window."}</strong><p>{savedOnly ? savedCount ? "Clear your search or filters to see your saved titles." : "Save an item from the calendar and it will stay here on this device." : wholeDatabaseSearch ? "Try a shorter title, creator, publisher, or horror tag, or clear a filter." : "Widen the date range or clear a filter. New records appear only after their date and source have been checked."}</p></div> : null}

        {filtered.length ? <div className="release-groups">
          {monthGroups.map(([month, items]) => <section className="month-group" key={month} aria-labelledby={`month-${month}`}>
            <div className="month-marker"><MonthHeading id={`month-${month}`}>{sort === "title" ? "Titles A–Z" : month ? monthLabel(`${month}-01`) : "Date to be announced"}</MonthHeading></div>
            <div className="release-grid">{items.map(release => <ReleaseCard key={release.id} release={release} saved={Boolean(saved[release.id])} savedOnly={savedOnly} onToggleSaved={toggleSaved} />)}</div>
          </section>)}
        </div> : null}
        {hasMoreResults ? <button type="button" className="button button--secondary" onClick={() => setVisibleResultCount(current => Math.min(current + resultBatchSize, filtered.length))}>Load more releases ({visibleReleases.length} of {filtered.length} shown)</button> : null}
        {hasMoreResults ? <div className="results-sentinel" ref={resultsSentinelRef} aria-hidden="true" /> : null}
        </div>
      </section>

      <OrnateDivider className="section-divider" />

      <section className="return-module">
        <div><p className="eyebrow">Stay curious. Stay haunted.</p><h2>Keep the next release in sight.</h2><p>RSS is for a reader app such as Feedly or NetNewsWire—not the browser. Copy the feed link and paste it there, or save titles on this device.</p></div>
        <div className="return-module__actions"><button type="button" className="button button--primary" onClick={() => void copyRssFeed()}>{feedCopied ? "Copied feed link" : "Copy RSS link"}</button><a className="button button--secondary" href="/?saved=1#full-calendar">View saved ({savedCount})</a></div>
      </section>

      <section className="method-section">
        <div><p className="eyebrow">How we curate</p><h2>Trust the trail,<br />not the rumor.</h2></div>
        <div><p>We gather release information from databases and official announcements, then show it here—we don’t choose or set release dates.</p><p>When sources disagree, we use the most reliable one we can find and link to the original evidence when it has a suitable public page.</p><a href="/sources">Read the sourcing policy <span aria-hidden="true">→</span></a></div>
      </section>
      <SiteFooter />
      </main>
    </>
  );
}

import type { WatchAvailability } from "./types";

export const watchLabels = {
  flatrate: "With subscription",
  free: "Free",
  ads: "Free with ads",
  rent: "Rent",
  buy: "Buy",
} as const;

export function normalizeWatchAvailability(value: unknown): WatchAvailability | undefined {
  if (!value || typeof value !== "object") return undefined;
  const item = value as Record<string, unknown>;
  if (item.region !== "US" || typeof item.source !== "string"
    || !/^https:\/\/www\.themoviedb\.org\/movie\/[0-9]+\/watch\?locale=US$/.test(item.source)
    || typeof item.checkedAt !== "string" || !Number.isFinite(Date.parse(item.checkedAt))
    || !item.offers || typeof item.offers !== "object") return undefined;
  const raw = item.offers as Record<string, unknown>;
  const offers = {} as WatchAvailability["offers"];
  for (const kind of Object.keys(watchLabels) as (keyof typeof watchLabels)[]) {
    if (!Array.isArray(raw[kind]) || raw[kind].some(name => typeof name !== "string" || !name.trim())) return undefined;
    offers[kind] = [...new Set(raw[kind] as string[])];
  }
  return { region: "US", source: item.source, checkedAt: item.checkedAt, offers };
}

export function currentWatchAvailability(value: WatchAvailability | undefined): WatchAvailability | undefined {
  const checked = value ? Date.parse(value.checkedAt) : NaN;
  return Number.isFinite(checked) && checked <= Date.now() && Date.now() - checked < 3 * 24 * 60 * 60 * 1000 ? value : undefined;
}

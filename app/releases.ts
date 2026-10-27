import type { Release } from "./types";

type ApiResponse = { releases?: Release[]; updatedAt?: string };
export type SearchResponse = ApiResponse & { truncated?: boolean };
const releaseViewVersion = "14";

async function requestReleases(query: URLSearchParams, signal?: AbortSignal): Promise<SearchResponse> {
  const response = await fetch(`/api/releases?${query}`, {
    headers: { Accept: "application/json" },
    signal,
  });
  if (!response.ok) throw new Error(`Release API returned ${response.status}`);
  const payload = (await response.json()) as SearchResponse;
  return {
    releases: Array.isArray(payload.releases) ? payload.releases : [],
    updatedAt: payload.updatedAt,
    truncated: payload.truncated === true,
  };
}

export async function fetchReleases(from: string, to: string, signal?: AbortSignal): Promise<ApiResponse> {
  const query = new URLSearchParams({ from, to, view: releaseViewVersion });
  return requestReleases(query, signal);
}

export async function searchReleases(search: string, signal?: AbortSignal): Promise<SearchResponse> {
  const query = new URLSearchParams({ q: search, view: releaseViewVersion });
  return requestReleases(query, signal);
}

export async function fetchRelease(id: string, signal?: AbortSignal): Promise<Release | null> {
  const query = new URLSearchParams({ id, view: releaseViewVersion });
  const payload = await requestReleases(query, signal);
  return payload.releases?.[0] ?? null;
}

export async function fetchSavedReleases(ids: string[], signal?: AbortSignal): Promise<Release[]> {
  const numeric = ids.map(id => id.replace(/^release-/, "")).filter(id => /^\d+$/.test(id));
  const releases: Release[] = [];
  for (let offset = 0; offset < numeric.length; offset += 100) {
    const query = new URLSearchParams({ ids: numeric.slice(offset, offset + 100).join(","), view: releaseViewVersion });
    const payload = await requestReleases(query, signal);
    releases.push(...(payload.releases ?? []));
  }
  return releases;
}

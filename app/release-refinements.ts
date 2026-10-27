import type { Release } from "./types";

export type ReleaseSort = "soonest" | "latest" | "title";

export function platformValues(release: Release): string[] {
  return (release.platform ?? "").split(",").map(value => value.trim()).filter(Boolean);
}

export function isVrRelease(release: Release): boolean {
  return release.mediaType === "video_game" && release.tags.some(tag => tag.toUpperCase() === "VR");
}

export function highlightReleaseTags(release: Release): string[] {
  const labels: string[] = [];
  const tags = release.tags.map(tag => tag.trim().toLowerCase().replace(/[\s_]+/g, " "));
  if (release.mediaType === "movie") {
    if (tags.some(tag => tag === "4k uhd" || tag === "4k")) labels.push("4K");
    if (tags.some(tag => tag === "re-release" || tag === "rerelease" || tag === "reissue" || tag === "remastered")) labels.push("Reissue");
  }
  if (isVrRelease(release)) labels.push("VR");
  return labels;
}

export function compareReleases(a: Release, b: Release, sort: ReleaseSort): number {
  const titleOrder = a.title.localeCompare(b.title, "en", { sensitivity: "base", numeric: true });
  if (sort === "title") return titleOrder || a.date.localeCompare(b.date) || a.id.localeCompare(b.id);
  // Undated titles stay at the end in either date direction.
  const aUndated = a.datePrecision === "tbd" || !a.date;
  const bUndated = b.datePrecision === "tbd" || !b.date;
  if (aUndated !== bUndated) return aUndated ? 1 : -1;
  return (sort === "latest" ? b.date.localeCompare(a.date) : a.date.localeCompare(b.date)) || titleOrder || a.id.localeCompare(b.id);
}

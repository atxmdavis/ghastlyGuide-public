import type { Release } from "./types";

export function releaseSlug(title: string): string {
  return title
    .normalize("NFKD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-|-$/g, "")
    .slice(0, 90) || "release";
}

export function releasePath(release: Pick<Release, "id" | "title">): string {
  const numericId = release.id.replace(/^release-/, "");
  return `/releases/${encodeURIComponent(numericId)}/${releaseSlug(release.title)}`;
}

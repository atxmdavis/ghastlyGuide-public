import type { Release } from "./types";

export function releaseSourceIsLinkable(release: Pick<Release, "sourceName">): boolean {
  return release.sourceName !== "Lunar Distribution";
}

export const mediaTypes = [
  "movie",
  "show",
  "video_game",
  "tabletop_game",
  "comic",
  "book",
] as const;

export type MediaType = (typeof mediaTypes)[number];
export type Confidence = "official" | "partner" | "database" | "editorial";

export type ReceptionRating = {
  provider: "Steam" | "TMDB";
  kind: "user" | "critic";
  score: number;
  scale: number;
  count: number;
  label?: string | null;
  source: string;
  updatedAt: string;
};

export type WatchAvailability = {
  region: "US";
  offers: Record<"flatrate" | "free" | "ads" | "rent" | "buy", string[]>;
  source: string;
  checkedAt: string;
};

export type Release = {
  id: string;
  title: string;
  creator: string;
  mediaType: MediaType;
  date: string;
  datePrecision: "day" | "month" | "year" | "tbd";
  publisher: string;
  platform?: string;
  region?: string;
  tags: string[];
  source: string;
  sourceName: string;
  confidence: Confidence;
  artwork?: string;
  synopsis?: string;
  trailer?: string;
  steamUrl?: string;
  watchAvailability?: WatchAvailability;
  verifiedAt?: string;
  reception?: ReceptionRating[];
};

export const mediaLabels: Record<MediaType, string> = {
  movie: "Movies",
  show: "Shows",
  video_game: "Video games",
  tabletop_game: "Tabletop",
  comic: "Comics",
  book: "Books",
};

export const releaseFilters = [
  { id: "movie_theatrical", label: "In Theaters" },
  { id: "movie_home", label: "Movies at home" },
  { id: "show", label: mediaLabels.show },
  { id: "video_game", label: mediaLabels.video_game },
  { id: "tabletop_game", label: mediaLabels.tabletop_game },
  { id: "comic", label: mediaLabels.comic },
  { id: "book", label: mediaLabels.book },
] as const;

export type ReleaseFilter = (typeof releaseFilters)[number]["id"];

const theatricalPlatforms = new Set(["Theatrical", "Limited theatrical"]);
const homeMoviePlatforms = new Set(["Physical", "Digital", "Streaming", "Television"]);

function releasePlatforms(release: Release): string[] {
  return (release.platform ?? "").split(",").map(value => value.trim()).filter(Boolean);
}

export function releaseMatchesFilter(release: Release, filter: ReleaseFilter): boolean {
  if (filter === "movie_theatrical") {
    return release.mediaType === "movie" && releasePlatforms(release).some(platform => theatricalPlatforms.has(platform));
  }
  if (filter === "movie_home") {
    return release.mediaType === "movie" && releasePlatforms(release).some(platform => homeMoviePlatforms.has(platform));
  }
  return release.mediaType === filter;
}

export function releaseTypeLabel(release: Release): string {
  const labels = releaseFilters.filter(filter => releaseMatchesFilter(release, filter.id)).map(filter => filter.label);
  return labels.length ? labels.join(" · ") : mediaLabels[release.mediaType];
}

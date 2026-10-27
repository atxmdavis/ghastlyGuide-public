import { useState, type ReactNode } from "react";
import { canAddToCalendar, downloadReleaseCalendar } from "./calendar-event";
import { formatReleaseDate } from "./date";
import { ratingCount, ratingValue } from "./release-rating";
import { releaseSourceIsLinkable } from "./release-source";
import { releaseMatchesFilter, releaseTypeLabel, type Release } from "./types";
import { releasePath } from "./release-url";
import MovieWatch from "./movie-watch";

function MediaTypeIcon({ release }: { release: Release }) {
  if (releaseMatchesFilter(release, "movie_theatrical")) {
    // Google Material Symbols Outlined: theaters, 24px, weight 400, fill 0.
    return <svg className="media-badge__icon" viewBox="0 -960 960 960" aria-hidden="true" focusable="false"><path fill="currentColor" stroke="none" d="M160-120v-720h80v80h80v-80h320v80h80v-80h80v720h-80v-80h-80v80H320v-80h-80v80h-80Zm80-160h80v-80h-80v80Zm0-160h80v-80h-80v80Zm0-160h80v-80h-80v80Zm400 320h80v-80h-80v80Zm0-160h80v-80h-80v80Zm0-160h80v-80h-80v80ZM400-200h160v-560H400v560Zm0-560h160-160Z" /></svg>;
  }

  const paths: Record<Release["mediaType"], ReactNode> = {
    movie: <><rect x="4" y="5" width="16" height="14" rx="1.5" /><path d="M4 9h16M8 5v4m8-4v4M8 15h8" /></>,
    show: <><rect x="3.5" y="6" width="17" height="12" rx="2" /><path d="m9 3 3 3 3-3M8 21h8" /></>,
    video_game: <><path d="M8.2 8h7.6a5 5 0 0 1 4.7 3.3l1 3a3 3 0 0 1-5 3l-1.4-1.3H8.9l-1.4 1.3a3 3 0 0 1-5-3l1-3A5 5 0 0 1 8.2 8Z" /><path d="M7 11v4m-2-2h4m7-1h.01M18 14h.01" /></>,
    tabletop_game: <><path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z" /><path d="m4 7.5 8 4.5 8-4.5M12 12v9" /><circle cx="12" cy="7.5" r=".7" fill="currentColor" stroke="none" /></>,
    comic: <><path d="M4 4h16v13H9l-5 4V4Z" /><path d="M8 8h8M8 12h6" /></>,
    book: <><path d="M3.5 5.5c3.5-.8 6.3-.2 8.5 1.8v12c-2.2-2-5-2.6-8.5-1.8v-12Zm17 0c-3.5-.8-6.3-.2-8.5 1.8v12c2.2-2 5-2.6 8.5-1.8v-12Z" /></>,
  };

  return <svg className="media-badge__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">{paths[release.mediaType]}</svg>;
}

export default function ReleaseCard({ release, saved, savedOnly = false, onToggleSaved }: {
  release: Release;
  saved: boolean;
  savedOnly?: boolean;
  onToggleSaved: (release: Release) => Promise<boolean>;
}) {
  const [justSaved, setJustSaved] = useState(false);

  async function toggleSaved() {
    if (!await onToggleSaved(release)) return;
    if (!saved) {
      setJustSaved(true);
      window.setTimeout(() => setJustSaved(false), 420);
    }
  }

  const rating = release.reception?.[0];

  return (
    <article className={`release-card release-card--${release.mediaType}${justSaved ? " is-just-saved" : ""}`}>
      <div className="release-card__art">
        <img
          className="release-card__art-bg"
          src={release.artwork || "/assets/release-placeholder.webp"}
          alt=""
          aria-hidden="true"
          loading="lazy"
          onError={event => {
            event.currentTarget.onerror = null;
            event.currentTarget.src = "/assets/release-placeholder.webp";
          }}
        />
        <img
          className="release-card__art-cover"
          src={release.artwork || "/assets/release-placeholder.webp"}
          alt={release.artwork ? `${release.title} cover art` : ""}
          loading="lazy"
          onError={event => {
            event.currentTarget.onerror = null;
            event.currentTarget.src = "/assets/release-placeholder.webp";
            event.currentTarget.alt = "";
          }}
        />
      </div>
      <div className="release-card__body">
        <div className="release-card__meta">
          <span className="media-badge"><MediaTypeIcon release={release} />{releaseTypeLabel(release)}</span>
          <span className="release-card__meta-separator" aria-hidden="true">•</span>
          <time dateTime={release.date} className="release-card__date">{formatReleaseDate(release.date, release.datePrecision)}</time>
        </div>
        <h3><a className="release-card__title" href={releasePath(release)}>{release.title}</a></h3>
        <p className="release-card__creator">{release.creator || release.publisher}</p>
        <MovieWatch release={release} compact />
        {release.synopsis ? <div className="release-card__copy">
          <p className="release-card__synopsis">{release.synopsis}</p>
        </div> : null}
        <a className="release-card__details-link" href={releasePath(release)}>
          View details <span aria-hidden="true">→</span>
        </a>
        {rating ? <a className="release-card__rating" href={rating.source} target="_blank" rel="noreferrer" title="View the current rating at its source">
          <strong>{ratingValue(rating.score, rating.scale)}</strong>
          <span>{rating.provider} · {ratingCount(rating.count)} ratings</span>
          <span className="sr-only"> (opens in a new tab)</span>
        </a> : null}
        <div className="release-card__footer">
          <div className="release-card__actions">
            <div className="release-card__buttons">
              <button type="button" className={saved ? "save-button is-saved" : "save-button"} onClick={toggleSaved} aria-pressed={saved} aria-label={savedOnly && saved ? `Remove from saved: ${release.title}` : `${saved ? "Remove" : "Save"} ${release.title}`}>
                {saved ? savedOnly ? "Remove from saved" : "Saved" : "Save"}
              </button>
              {canAddToCalendar(release) ? <button type="button" className="calendar-button" onClick={() => downloadReleaseCalendar(release)} aria-label={`Save ${release.title} to your local calendar`}>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" strokeWidth="1.75" d="M7 3.5v3M17 3.5v3M4.5 8.5h15v12h-15zM4.5 12.5h15" /></svg>
              </button> : null}
            </div>
          </div>
          <div className="release-card__links">
          {release.mediaType === "video_game" && release.steamUrl ? <a className="release-card__source release-card__steam" href={release.steamUrl} rel="noreferrer" target="_blank">View on Steam<span className="sr-only"> (opens in a new tab)</span></a> : null}
          {!releaseSourceIsLinkable(release)
            ? <span className="release-card__source">{release.sourceName}</span>
            : <a className="release-card__source" href={release.source} rel="noreferrer" target="_blank">
              {release.sourceName}<span className="sr-only"> (opens in a new tab)</span>
            </a>}
          </div>
        </div>
      </div>
    </article>
  );
}

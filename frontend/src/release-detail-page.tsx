import { useEffect, useState } from "react";
import { browseReturn } from "./browse-state";
import { canAddToCalendar, downloadReleaseCalendar } from "./calendar-event";
import { formatReleaseDate } from "./date";
import { ratingCount, ratingUpdated, ratingValue } from "./release-rating";
import { releaseSourceIsLinkable } from "./release-source";
import { fetchRelease } from "./releases";
import { loadSavedResult, subscribeSaved, updateSaved } from "./saved";
import TrailerModal from "./trailer-modal";
import { releaseTypeLabel, type Release } from "./types";
import { youtubeVideoId } from "./youtube";
import SiteFooter from "./site-footer";
import SiteHeader from "./site-header";
import MovieWatch from "./movie-watch";

export default function ReleaseDetailPage({ releaseId }: { releaseId: string }) {
  const [release, setRelease] = useState<Release | null>(null);
  const [failed, setFailed] = useState<"missing" | "error" | null>(null);
  const [revision, setRevision] = useState(0);
  const [back] = useState(browseReturn);
  const [saved, setSaved] = useState(false);
  const [savedError, setSavedError] = useState("");
  const [trailerOpen, setTrailerOpen] = useState(false);

  useEffect(() => {
    const controller = new AbortController();
    void fetchRelease(releaseId, controller.signal).then(item => {
      setRelease(item);
      setFailed(item ? null : "missing");
      if (item) {
        document.title = `${item.title} Release Date — Ghastly Guide`;
        const savedResult = loadSavedResult();
        setSaved(Boolean(savedResult.saves[item.id]));
        setSavedError(savedResult.warning);
      }
    }).catch(error => {
      if (!(error instanceof DOMException && error.name === "AbortError")) setFailed("error");
    });
    return () => controller.abort();
  }, [releaseId, revision]);

  useEffect(() => subscribeSaved(result => {
    setSaved(Boolean(result.saves[`release-${releaseId}`]));
    setSavedError(result.warning);
  }), [releaseId]);

  async function toggleSaved(item: Release) {
    const result = await updateSaved(current => {
      const next = { ...current };
      if (next[item.id]) delete next[item.id]; else next[item.id] = item;
      return next;
    });
    setSaved(Boolean(result.saves[item.id]));
    setSavedError(result.warning);
  }

  const trailerAvailable = Boolean(release?.trailer && youtubeVideoId(release.trailer));
  const mediaAvailable = Boolean(release?.artwork || trailerAvailable);
  const sourceLinkable = Boolean(release && releaseSourceIsLinkable(release));

  return <>
    <SiteHeader />
    <main id="main-content" tabIndex={-1}>
      <article className={mediaAvailable ? "release-detail" : "release-detail release-detail--no-media"}>
        {failed ? <div className="notice" role="alert"><h1>{failed === "missing" ? "That release has vanished." : "This release could not be loaded."}</h1>{failed === "error" ? <><p>Release details are temporarily unavailable. Please try again.</p><button type="button" className="button button--primary" onClick={() => { setFailed(null); setRevision(current => current + 1); }}>Try again</button></> : null}<p><a href={back.href}>Return to the Ghastly Calendar</a></p></div> : null}
        {!failed && !release ? <div className="notice" role="status"><strong>Opening the archive…</strong></div> : null}
        {release ? <>
          {mediaAvailable ? <div className="release-detail__media">
            <a className="release-detail__back" href={back.href} aria-label="Back to guide">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" strokeWidth="1.75" d="M15 5.5 8 12l7 6.5" /></svg>
            </a>
            {release.artwork ? <img className="release-detail__art" src={release.artwork} alt={`${release.title} cover art`} width="360" height="520" onError={event => { event.currentTarget.onerror = null; event.currentTarget.src = "/assets/release-placeholder.webp"; event.currentTarget.alt = ""; }} /> : null}
            {trailerAvailable ? <button type="button" className="button button--primary release-detail__trailer" onClick={() => setTrailerOpen(true)}>Play trailer</button> : null}
          </div> : <a className="release-detail__back" href={back.href} aria-label="Back to guide">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" strokeWidth="1.75" d="M15 5.5 8 12l7 6.5" /></svg>
          </a>}
          <div className="release-detail__body">
            <p className="eyebrow">{releaseTypeLabel(release)}</p>
            <h1>{release.title}</h1>
            {release.creator ? <p className="release-detail__creator">{release.creator}</p> : null}
            {release.synopsis ? <p className="release-detail__synopsis">{release.synopsis}</p> : null}
            <dl>
              <div><dt>Release date</dt><dd><time dateTime={release.date}>{formatReleaseDate(release.date, release.datePrecision)}</time></dd></div>
              {release.publisher ? <div><dt>Publisher</dt><dd>{release.publisher}</dd></div> : null}
              {release.platform ? <div><dt>Platform or format</dt><dd>{release.platform}</dd></div> : null}
              {release.region ? <div><dt>Region</dt><dd>{release.region}</dd></div> : null}
              <div><dt>Date source</dt><dd>{sourceLinkable
                ? <a href={release.source} target="_blank" rel="noreferrer">{release.sourceName}<span className="sr-only"> (opens in a new tab)</span></a>
                : release.sourceName}</dd></div>
              {release.verifiedAt ? <div><dt>Last verified</dt><dd><time dateTime={release.verifiedAt}>{ratingUpdated(release.verifiedAt)}</time></dd></div> : null}
            </dl>
            {release.reception?.length ? <section className="release-detail__reception" aria-labelledby="release-reception-heading">
              <h2 id="release-reception-heading">Current reception</h2>
              <ul className="reception-list">
                {release.reception.map(rating => <li key={`${rating.provider}-${rating.kind}`}>
                  <a href={rating.source} target="_blank" rel="noreferrer" title={`Current rating, updated ${new Date(rating.updatedAt).toLocaleDateString()}`}>
                    <strong>{ratingValue(rating.score, rating.scale)}</strong>
                    <span className="reception-list__copy">
                      <span>{rating.provider}{rating.label ? ` · ${rating.label}` : ""} · {ratingCount(rating.count)} ratings</span>
                      <small>Current · updated {ratingUpdated(rating.updatedAt)}</small>
                    </span>
                    <span className="sr-only"> (opens in a new tab)</span>
                  </a>
                </li>)}
              </ul>
            </section> : null}
            <MovieWatch release={release} />
            {release.tags.some(tag => tag.trim().toLowerCase() !== "horror") ? <section className="release-detail__tags" aria-labelledby="release-tags-heading">
              <h2 id="release-tags-heading">Tags</h2>
              <ul>{release.tags.map(tag => <li key={tag}>{tag}</li>)}</ul>
            </section> : null}
            <div className="release-detail__actions">
              <button type="button" className={saved ? "button button--secondary is-saved" : "button button--secondary"} onClick={() => toggleSaved(release)} aria-pressed={saved}>{saved ? "Saved" : "Save"}</button>
              {canAddToCalendar(release) ? <button type="button" className="button button--secondary" onClick={() => downloadReleaseCalendar(release)}>Download to calendar</button> : null}
              {release.mediaType === "video_game" && release.steamUrl ? <a className="button button--secondary" href={release.steamUrl} target="_blank" rel="noreferrer">View on Steam<span className="sr-only"> (opens in a new tab)</span></a> : null}
              {sourceLinkable ? <a className="button button--secondary" href={release.source} target="_blank" rel="noreferrer">Check source<span className="sr-only"> (opens in a new tab)</span></a> : null}
              <a className="button button--secondary" href={back.href}>Back to guide</a>
            </div>
            {savedError ? <p className="release-detail__error" role="alert">{savedError}</p> : null}
          </div>
        </> : null}
      </article>
      <SiteFooter />
    </main>
    {trailerOpen && release?.trailer ? <TrailerModal title={release.title} url={release.trailer} onClose={() => setTrailerOpen(false)} /> : null}
  </>;
}

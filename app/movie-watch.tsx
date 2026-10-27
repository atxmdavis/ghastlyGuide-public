import { ratingUpdated } from "./release-rating";
import { releaseMatchesFilter, type Release } from "./types";
import { currentWatchAvailability, watchLabels } from "./watch-availability";

export default function MovieWatch({ release, compact = false }: { release: Release; compact?: boolean }) {
  if (!releaseMatchesFilter(release, "movie_home")) return null;
  const availability = currentWatchAvailability(release.watchAvailability);
  const groups = (Object.keys(watchLabels) as (keyof typeof watchLabels)[])
    .filter(kind => availability?.offers[kind].length);
  if (compact) {
    const providers = availability?.offers.flatrate ?? [];
    return <div className="release-card__watch">
      {providers.length ? <p>Streaming with subscription: {providers.join(", ")} <span>(U.S.)</span></p> : <p>Subscription availability not confirmed.</p>}
      {availability ? <small>Availability via <a href={availability.source} target="_blank" rel="noreferrer">JustWatch / TMDB<span className="sr-only"> (opens in a new tab)</span></a></small> : null}
    </div>;
  }
  return <section className="release-detail__watch" aria-labelledby="movie-watch-heading">
    <h2 id="movie-watch-heading">Where to watch <span>(U.S.)</span></h2>
    {groups.length && availability ? <dl className="watch-options">{groups.map(kind => <div key={kind}>
      <dt>{watchLabels[kind]}</dt><dd>{availability.offers[kind].join(", ")}</dd>
    </div>)}</dl> : <p>Availability not confirmed.</p>}
    {availability ? <p className="watch-attribution"><a href={availability.source} target="_blank" rel="noreferrer">Check viewing options<span className="sr-only"> (opens in a new tab)</span></a><br />Availability via JustWatch / TMDB · Checked {ratingUpdated(availability.checkedAt)}. Availability can change.</p> : null}
  </section>;
}

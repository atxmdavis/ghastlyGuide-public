import SiteFooter from "./site-footer";
import SiteHeader from "./site-header";

const sourceGroups = [
  {
    medium: "Movies",
    now: "The Movie Database (TMDB) for U.S. theatrical, digital, physical, and TV dates, including international horror that actually reaches U.S. screens or shelves. Each card still links to the evidence behind that date.",
    later: "U.S. viewing availability comes from JustWatch via TMDB, refreshed separately from release dates. Subscription, free, rental, and purchase options are kept separate; availability can change. We do not scrape streaming calendars.",
  },
  {
    medium: "Shows",
    now: "TMDB for series and season dates, plus TVmaze for currently scheduled horror season premieres—not every weekly episode. A matching studio, network, or TMDB record stays in front.",
    later: "We will not ingest full episode lists. Platform schedules can be added only through an official or licensed feed.",
  },
  {
    medium: "Video games",
    now: "IGDB is the primary date. RAWG can surface extra horror titles, but those stay lower-confidence until a stronger source confirms them. Released PC games can also show current Steam user-review percentages when IGDB provides an exact Steam ID.",
    later: "PlayStation and Xbox storefront data can join when we have a reviewed, licensed path—not a scrape.",
  },
  {
    medium: "Tabletop",
    now: "Publisher calendars we have reviewed, including Asmodee USA’s U.S. retail dates for a narrow horror list. Crowdfunding, freight, and store-shelf dates stay separate when the source is clear.",
    later: "BoardGameGeek is not a backbone. It can enrich metadata only after application approval and a license that allows it.",
  },
  {
    medium: "Comics",
    now: "Lunar Distribution’s public product workbooks provide U.S. comic-shop dates for explicitly horror-related titles. Penguin Random House titles and publisher solicitations fill out collected editions. Because Lunar’s evidence is a direct spreadsheet download, we name the distributor without linking visitors to the file.",
    later: "Lunar does not supply genres, so its descriptions must contain a strong horror signal. Metron can enrich confirmed matches, but it is not complete enough to set every date.",
  },
  {
    medium: "Books",
    now: "Penguin Random House’s API for its titles, plus reviewed publisher product pages. A publisher product page beats a catalog guess.",
    later: "More imprints can join after access is approved. Open Library is not the calendar’s source of truth.",
  },
];

export default function SourcesPage() {
  return <><SiteHeader active="sources" />
    <main id="main-content" tabIndex={-1}>
    <section className="page-hero"><p className="eyebrow">How we curate</p><h1>A date is only<br /><em>as good as its source.</em></h1></section>
    <section className="source-policy">
      <div className="source-policy__intro">
        <p>Ghastly Guide is a calendar, not a rumor mill. Something can appear here only after we can point to where the date came from.</p>
        <h2>Discovery is not confirmation</h2>
        <p>A database might whisper that a vampire game exists. That is useful. It is not enough. The date you see is the one the strongest source will stand behind: a studio, network, publisher, platform, creator, or a licensed catalog that is allowed to say so.</p>
        <p>Every card keeps that trail. If two sources disagree, the higher-confidence record wins, and you can open the original evidence when it has a suitable public page. Direct-download evidence is named without turning the file into a visitor-facing link. When an official announcement arrives later, it can replace a weaker date instead of sitting beside a rumor.</p>
        <p>Release pages also show when the evidence was last verified. To report a changed date or catalog error, <a href="/contact">send a correction with the strongest public source available</a>.</p>
      </div>
      <div className="confidence-ladder"><span>How sure are we?</span><ol>
        <li><strong>Official</strong><p>The people who set the date: a studio, network, publisher, creator, platform, or distributor. This is the gold standard.</p></li>
        <li><strong>Partner</strong><p>A licensed catalog we are allowed to use, such as TMDB, IGDB, or a publisher API. Strong, but an official page still wins if they conflict.</p></li>
        <li><strong>Database</strong><p>Community or library metadata such as RAWG or TVmaze. Helpful for finding titles and filling gaps. It never silently overwrites a better source.</p></li>
        <li><strong>Editorial</strong><p>A human-reviewed date when no structured feed exists. There is still a public evidence URL—no anonymous “we heard.”</p></li>
      </ol></div>
      <div className="source-table" role="table" aria-label="Where release dates come from">
        <div className="source-row source-row--head" role="row"><span role="columnheader">Medium</span><span role="columnheader">What we use now</span><span role="columnheader">What we skip, for now</span></div>
        {sourceGroups.map(group => <div className="source-row" role="row" key={group.medium}><strong role="cell">{group.medium}</strong><span role="cell">{group.now}</span><span role="cell">{group.later}</span></div>)}
      </div>
    </section>
    <section className="attribution">
      <h2>We do not scrape in the dark</h2>
      <p>Ghastly Guide does not raid private APIs or copy someone else’s calendar wholesale. Sources are fetched on our servers, rate limits are respected, and required credits stay on the site. Your browser does not call TMDB, IGDB, or the others directly.</p>
      <p>Reception is separate from release-date evidence. TMDB and Steam user ratings appear only after release, include the number of ratings, link to the provider, and show the current aggregate—not a score frozen on release day.</p>
      <p>If this calendar ever becomes a commercial product, providers that limit free access will need their own licenses. Until then, the rule is simple: if we cannot name the source, it does not ship.</p>
    </section>
    <SiteFooter />
    </main>
  </>;
}

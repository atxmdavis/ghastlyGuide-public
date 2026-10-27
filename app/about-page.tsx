import SiteFooter from "./site-footer";
import SiteHeader from "./site-header";

export default function AboutPage() {
  return <><SiteHeader active="about" />
    <main id="main-content" tabIndex={-1}>
    <section className="page-hero"><p className="eyebrow">About Ghastly Guide</p><h1>A calendar for<br /><em>the uncanny.</em></h1></section>
    <section className="source-policy">
      <div className="source-policy__intro">
        <p>Ghastly Guide tracks new horror across screens, shelves, consoles, and tabletops without turning rumor into fact.</p>
        <h2>One date. Many forms.</h2>
        <p>Horror does not live in one aisle. A film might hit theaters the same week a game lands on consoles, a comic hits shops, and a novel hits shelves. Those industries do not share a single trustworthy calendar, so people end up stitching rumors together.</p>
        <p>We put them in one place anyway—and we keep the details that actually matter for each medium: platform, publisher, region, and how precise the date is. A month-only announcement stays a month. We will not pretend it is a Tuesday.</p>
        <p>Every card is evidence-first. It links back to its source. If a weaker date is later replaced by an official one, the rumor does not get to keep a seat at the table.</p>
        <h2>What you can do here</h2>
        <p>Browse upcoming and recent horror, filter by medium, and save titles on this device. Saved releases live in your browser. There is no account, no profile, and no feed of other people’s lists.</p>
        <p>If you use a reader app, you can copy the RSS link from the homepage and paste it there. The browser itself is not a feed reader—that file is for the app.</p>
        <h2>Corrections are part of the work</h2>
        <p>Ghastly Guide is independently maintained. Each release keeps its evidence link, confidence level, date precision, and verification history so a change can be checked instead of quietly guessed.</p>
        <p>If a date moves or a release does not belong, <a href="/contact">send the title and the strongest public source you have</a>. We review corrections against the same sourcing ladder used for new entries.</p>
        <p>Want the fine print on TMDB, IGDB, TVmaze, and the rest? That lives on the sourcing page, including what we use now and what we refuse to scrape.</p>
        <p><a href="/sources">How we curate <span aria-hidden="true">→</span></a></p>
      </div>
      <div className="provider-credit">
        <a href="https://www.themoviedb.org/" target="_blank" rel="noreferrer" aria-label="Visit The Movie Database"><img src="/tmdb-logo.svg" alt="TMDB" /></a>
        <p>This product uses the TMDB API but is not endorsed or certified by TMDB.</p>
        <ul className="provider-credit__list">
          <li><a href="https://www.themoviedb.org/" target="_blank" rel="noreferrer">TMDB</a> — movies and shows</li>
          <li><a href="https://www.igdb.com/" target="_blank" rel="noreferrer">IGDB</a> — primary video-game dates</li>
          <li><a href="https://store.steampowered.com/" target="_blank" rel="noreferrer">Steam</a> — current user-review aggregates for matched PC games</li>
          <li><a href="https://rawg.io/" target="_blank" rel="noreferrer">RAWG</a> — supplemental game discovery</li>
          <li><a href="https://www.tvmaze.com/" target="_blank" rel="noreferrer">TVmaze</a> — current horror season premieres</li>
          <li><a href="https://www.penguinrandomhouse.com/" target="_blank" rel="noreferrer">Penguin Random House</a> and Google Books — book dates</li>
        </ul>
        <p>RAWG records link back to their source and remain lower-confidence until IGDB, a platform, or a publisher confirms the date.</p>
      </div>
    </section>
    <SiteFooter />
    </main>
  </>;
}

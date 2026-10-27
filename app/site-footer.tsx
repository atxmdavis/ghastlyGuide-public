import { analyticsIsConfigured, openAnalyticsPreferences } from "./analytics";

export default function SiteFooter() {
  return (
    <footer className="site-footer">
      <div className="site-footer__ornament" aria-hidden="true"><span /><img src="/assets/skull-divider-ornament.webp" alt="" /><span /></div>
      <div className="site-footer__frame">
        <i className="site-footer__corner site-footer__corner--tl" aria-hidden="true" />
        <i className="site-footer__corner site-footer__corner--tr" aria-hidden="true" />
        <i className="site-footer__corner site-footer__corner--bl" aria-hidden="true" />
        <i className="site-footer__corner site-footer__corner--br" aria-hidden="true" />
        <div className="site-footer__brand">
          <a className="site-footer__brandmark" href="/" aria-label="Ghastly Guide home"><img src="/assets/ghastly-guide-logo-small.webp" width="1169" height="1345" alt="" /></a>
          <p>Your guide to what haunts next.<br />Across various media.</p>
        </div>
        <nav aria-label="Browse"><strong>Browse</strong><a href="/#full-calendar">Ghastly Calendar</a><a href="/?range=week#full-calendar">This week</a><a href="/movies">Movies</a><a href="/shows">Shows</a><a href="/games">Games</a><a href="/comics">Comics</a><a href="/books">Books</a><a href="/tabletop">Tabletop</a><a href="/?saved=1#full-calendar">Saved</a></nav>
        <nav aria-label="About"><strong>About</strong><a href="/about">Our mission</a><a href="/sources">How we curate</a><a href="/contact">Suggest a title</a><a href="/contact">Contact</a><a href="/feed.xml">RSS feed</a>{analyticsIsConfigured() ? <button type="button" className="site-footer__privacy" onClick={openAnalyticsPreferences}>Privacy choices</button> : null}</nav>
        <div className="site-footer__statement">
          <i className="site-footer__statement-corner site-footer__statement-corner--tl" aria-hidden="true" />
          <i className="site-footer__statement-corner site-footer__statement-corner--tr" aria-hidden="true" />
          <i className="site-footer__statement-corner site-footer__statement-corner--bl" aria-hidden="true" />
          <i className="site-footer__statement-corner site-footer__statement-corner--br" aria-hidden="true" />
          <img src="/assets/footer-skull.webp" alt="" aria-hidden="true" />
          <div className="site-footer__statement-copy"><span aria-hidden="true" /><strong>Some stories lurk<br />in the dark.</strong></div>
        </div>
      </div>
      <p className="site-footer__legal">© {new Date().getFullYear()} Ghastly Guide.</p>
    </footer>
  );
}

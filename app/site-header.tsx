import { useEffect, useRef, useState, type ReactNode } from "react";

const primaryLinks = [
  { label: "Calendar", href: "/#full-calendar", active: "calendar" },
  { label: "This Week", href: "/?range=week#full-calendar", active: "this-week" },
  { label: "Saved", href: "/?saved=1#full-calendar", active: "saved" },
  { label: "Contact", href: "/contact", active: "contact" },
];

function activeSection(explicit?: string): string {
  if (explicit) return explicit;
  const params = new URLSearchParams(window.location.search);
  if (params.get("saved") === "1") return "saved";
  if (params.get("range") === "week") return "this-week";
  if (window.location.hash === "#full-calendar") return "calendar";
  return "";
}

export default function SiteHeader({ active, savedCount = 0, belowHero = false, heading }: { active?: string; savedCount?: number; belowHero?: boolean; heading?: ReactNode }) {
  const headerRef = useRef<HTMLElement>(null);
  const menuButtonRef = useRef<HTMLButtonElement>(null);
  const [menuOpen, setMenuOpen] = useState(false);
  const [showBackToTop, setShowBackToTop] = useState(false);
  const currentSection = activeSection(active);

  useEffect(() => {
    if (!menuOpen) return;
    const close = (event: KeyboardEvent) => { if (event.key === "Escape") { setMenuOpen(false); menuButtonRef.current?.focus(); } };
    headerRef.current?.querySelector<HTMLAnchorElement>("nav a")?.focus();
    window.addEventListener("keydown", close);
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    return () => {
      window.removeEventListener("keydown", close);
      document.body.style.overflow = previousOverflow;
    };
  }, [menuOpen]);

  useEffect(() => {
    const desktop = window.matchMedia("(min-width: 981px)");
    const closeOnDesktop = () => { if (desktop.matches) setMenuOpen(false); };
    desktop.addEventListener("change", closeOnDesktop);
    return () => desktop.removeEventListener("change", closeOnDesktop);
  }, []);

  useEffect(() => {
    const onScroll = () => setShowBackToTop(window.scrollY > 480);
    onScroll();
    window.addEventListener("scroll", onScroll, { passive: true });
    return () => window.removeEventListener("scroll", onScroll);
  }, []);

  function scrollToTop() {
    const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    window.scrollTo({ top: 0, behavior: reduceMotion ? "auto" : "smooth" });
  }

  return (
    <>
      <header ref={headerRef} onBlur={event => { if (menuOpen && !event.currentTarget.contains(event.relatedTarget)) setMenuOpen(false); }} className={belowHero ? "site-header site-header--below-hero" : "site-header"}>
        <div className="site-header__brand">
          <a className="wordmark" href="/" aria-label="Ghastly Guide home">
            <img src="/assets/ghastly-guide-logo-small.webp" width="1169" height="1345" alt="" />
          </a>
          {heading ? <div className="site-header__heading">{heading}</div> : null}
        </div>
        <nav className={menuOpen ? "is-open" : ""} aria-label="Primary navigation" id="primary-navigation">
          {primaryLinks.map(link => <a key={link.active} aria-current={currentSection === link.active ? "page" : undefined} aria-label={link.active === "saved" ? `Saved, ${savedCount} releases` : undefined} href={link.href} onClick={() => setMenuOpen(false)}>{link.label}</a>)}
        </nav>
        <div className="site-header__actions">
          <button ref={menuButtonRef} className={menuOpen ? "menu-button is-open" : "menu-button"} type="button" aria-expanded={menuOpen} aria-controls="primary-navigation" aria-label={menuOpen ? "Close menu" : "Open menu"} onClick={() => {
            if (!menuOpen && headerRef.current) {
              const headerTop = headerRef.current.getBoundingClientRect().top;
              const logoTop = headerRef.current.querySelector(".wordmark")?.getBoundingClientRect().top ?? headerTop;
              window.scrollTo({ top: window.scrollY + Math.min(headerTop, logoTop), behavior: "instant" });
            }
            setMenuOpen(open => !open);
          }}><span /><span /><span /></button>
        </div>
      </header>
      {menuOpen ? <button type="button" className="menu-scrim" tabIndex={-1} aria-label="Close menu" onClick={() => setMenuOpen(false)} /> : null}
      {showBackToTop ? <button type="button" className="back-to-top" onClick={scrollToTop} aria-label="Back to top">↑</button> : null}
    </>
  );
}

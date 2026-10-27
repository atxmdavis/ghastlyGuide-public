import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import AboutPage from "../../app/about-page";
import AnalyticsConsent from "../../app/analytics-consent";
import ContactPage from "../../app/contact-page";
import "../../app/globals.css";
import Home from "../../app/page";
import ReleaseDetailPage from "../../app/release-detail-page";
import SourcesPage from "../../app/sources-page";

const path = window.location.pathname.replace(/\/+$/, "") || "/";
const releaseMatch = path.match(/^\/releases\/(\d+)(?:\/[^/]*)?$/);
const listingRoute = path === "/" || /^\/(movies|shows|games|tabletop|comics|books)$/.test(path) || /^\/calendar\/20\d{2}\/(0[1-9]|1[0-2])$/.test(path);
const notFound = <main id="main-content" className="notice"><h1>That page has vanished.</h1><p><a href="/">Return to the Ghastly Calendar</a></p></main>;
const content = releaseMatch ? <ReleaseDetailPage releaseId={releaseMatch[1]} /> : path === "/sources" ? <SourcesPage /> : path === "/about" ? <AboutPage /> : path === "/contact" ? <ContactPage /> : listingRoute ? <Home /> : notFound;

if (path === "/sources") document.title = "Sources — Ghastly Guide";
if (path === "/about") document.title = "About — Ghastly Guide";
if (path === "/contact") document.title = "Contact — Ghastly Guide";
if (!releaseMatch && !listingRoute && !["/sources", "/about", "/contact"].includes(path)) document.title = "Page Not Found — Ghastly Guide";

createRoot(document.getElementById("root")!).render(<StrictMode>{content}<AnalyticsConsent /></StrictMode>);

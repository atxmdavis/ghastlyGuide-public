const measurementId = import.meta.env.VITE_GA_MEASUREMENT_ID?.trim() ?? "";

export const analyticsPreferenceKey = "ghastly-guide:analytics-consent:v1";

declare global {
  interface Window {
    [key: `ga-disable-${string}`]: boolean | undefined;
    dataLayer?: unknown[];
    gtag?: (...args: unknown[]) => void;
  }
}

let tagInstalled = false;
let trackingGranted = false;

export function analyticsIsConfigured(): boolean {
  return import.meta.env.PROD && /^G-[A-Z0-9]+$/i.test(measurementId);
}

function isProductionHost(): boolean {
  return window.location.hostname === "ghastlyguide.com" || window.location.hostname === "www.ghastlyguide.com";
}

export function installAnalyticsTag(): void {
  if (tagInstalled || !analyticsIsConfigured() || !isProductionHost()) return;
  tagInstalled = true;
  if (typeof window.gtag === "function") return;

  window.dataLayer = window.dataLayer ?? [];
  window.gtag = function gtag() {
    // gtag.js reads dataLayer entries as Arguments objects, not rest arrays.
    // eslint-disable-next-line prefer-rest-params -- GA command queue requires `arguments`
    window.dataLayer?.push(arguments);
  };
  window.gtag("consent", "default", {
    ad_storage: "denied",
    ad_user_data: "denied",
    ad_personalization: "denied",
    analytics_storage: "denied",
    wait_for_update: 500,
  });

  const script = document.createElement("script");
  script.async = true;
  script.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(measurementId)}`;
  script.dataset.ghastlyAnalytics = "true";
  document.head.append(script);
}

export function initializeAnalytics(): void {
  if (!analyticsIsConfigured() || !isProductionHost()) return;
  installAnalyticsTag();
  if (!window.gtag || trackingGranted) return;
  window[`ga-disable-${measurementId}`] = false;
  trackingGranted = true;
  window.gtag("consent", "update", { analytics_storage: "granted" });
  window.gtag("js", new Date());
  window.gtag("config", measurementId, {
    allow_google_signals: false,
    allow_ad_personalization_signals: false,
    cookie_flags: "SameSite=Lax;Secure",
    send_page_view: true,
  });
}

export function revokeAnalytics(): void {
  if (!analyticsIsConfigured() || !isProductionHost()) return;
  // Disable collection before updating consent; denied consent alone can allow cookieless pings.
  window[`ga-disable-${measurementId}`] = true;
  trackingGranted = false;
  window.gtag?.("consent", "update", { analytics_storage: "denied" });
  try {
    const names = document.cookie.split(";").map(cookie => cookie.trim().split("=")[0])
      .filter(name => name === "_ga" || name.startsWith("_ga_"));
    const labels = window.location.hostname.split(".");
    const domains = ["", ...labels.slice(0, -1).flatMap((_, index) => {
      const domain = labels.slice(index).join(".");
      return [`; Domain=${domain}`, `; Domain=.${domain}`];
    })];
    for (const name of names) {
      for (const domain of domains) {
        document.cookie = `${name}=; Max-Age=0; Path=/${domain}; SameSite=Lax; Secure`;
      }
    }
  } catch {
    // Collection remains disabled even when the browser blocks cookie access.
  }
}

const preferencesListeners = new Set<() => void>();

export function subscribeAnalyticsPreferences(listener: () => void): () => void {
  preferencesListeners.add(listener);
  return () => {
    preferencesListeners.delete(listener);
  };
}

export function openAnalyticsPreferences(): void {
  for (const listener of preferencesListeners) listener();
}

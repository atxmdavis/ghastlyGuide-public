import { useEffect, useState } from "react";
import { createPortal } from "react-dom";
import {
  analyticsIsConfigured,
  analyticsPreferenceKey,
  initializeAnalytics,
  installAnalyticsTag,
  revokeAnalytics,
  subscribeAnalyticsPreferences,
} from "./analytics";

type AnalyticsPreference = "accepted" | "declined" | null;

function readPreference(): AnalyticsPreference {
  try {
    const value = window.localStorage.getItem(analyticsPreferenceKey);
    return value === "accepted" || value === "declined" ? value : null;
  } catch {
    return null;
  }
}

function storePreference(value: Exclude<AnalyticsPreference, null>): void {
  try {
    window.localStorage.setItem(analyticsPreferenceKey, value);
  } catch {
    // The choice still applies for this page load when storage is unavailable.
  }
}

export default function AnalyticsConsent() {
  const [preference, setPreference] = useState<AnalyticsPreference>(readPreference);
  const [isOpen, setIsOpen] = useState(() => preference === null);

  useEffect(() => {
    installAnalyticsTag();
  }, []);

  useEffect(() => {
    if (preference === "accepted") initializeAnalytics();
    else revokeAnalytics();
  }, [preference]);

  useEffect(() => {
    const onStorage = (event: StorageEvent) => {
      if (event.key === analyticsPreferenceKey || event.key === null) setPreference(readPreference());
    };
    window.addEventListener("storage", onStorage);
    return () => window.removeEventListener("storage", onStorage);
  }, []);

  useEffect(() => subscribeAnalyticsPreferences(() => setIsOpen(true)), []);

  useEffect(() => {
    if (!isOpen || !analyticsIsConfigured()) return;
    function onKey(event: KeyboardEvent) {
      if (event.key === "Escape") setIsOpen(false);
    }
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [isOpen]);

  if (!analyticsIsConfigured() || !isOpen) return null;

  function choose(nextPreference: Exclude<AnalyticsPreference, null>) {
    if (nextPreference === "accepted") initializeAnalytics();
    else revokeAnalytics();
    storePreference(nextPreference);
    setPreference(nextPreference);
    setIsOpen(false);
  }

  return createPortal(
    <section className="guide-notice" aria-labelledby="guide-notice-title">
      <div>
        <strong id="guide-notice-title">Help improve the guide?</strong>
        <p>We use Google Analytics to understand which pages are useful. Cookies are set only if you accept, and advertising personalization stays off.</p>
      </div>
      <div className="guide-notice__actions">
        <button type="button" onClick={() => choose("declined")}>Decline</button>
        <button type="button" className="guide-notice__accept" onClick={() => choose("accepted")}>Accept</button>
      </div>
    </section>,
    document.body,
  );
}

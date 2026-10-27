import { addDays } from "./date";
import { releaseSourceIsLinkable } from "./release-source";
import type { Release } from "./types";

function icsEscape(value: string): string {
  return value.replace(/\\/g, "\\\\").replace(/\n/g, "\\n").replace(/,/g, "\\,").replace(/;/g, "\\;");
}

function icsFold(line: string): string {
  const encoder = new TextEncoder();
  if (encoder.encode(line).length <= 75) return line;
  const units = [...line];
  const parts: string[] = [];
  let current = "";
  for (const unit of units) {
    const next = current + unit;
    if (encoder.encode(next).length > 75) {
      parts.push(current);
      current = ` ${unit}`;
    } else {
      current = next;
    }
  }
  if (current) parts.push(current);
  return parts.join("\r\n");
}

function icsDate(iso: string): string {
  return iso.replaceAll("-", "");
}

function icsStamp(date = new Date()): string {
  return date.toISOString().replace(/[-:]/g, "").replace(/\.\d{3}Z$/, "Z");
}

function wrapCalendar(inner: string[]): string {
  const lines = [
    "BEGIN:VCALENDAR",
    "VERSION:2.0",
    "PRODID:-//Ghastly Guide//EN",
    "CALSCALE:GREGORIAN",
    "METHOD:PUBLISH",
    ...inner,
    "END:VCALENDAR",
  ];
  return `${lines.map(icsFold).join("\r\n")}\r\n`;
}

function releaseEventLines(release: Release, stamp: string): string[] {
  const sourceLinkable = releaseSourceIsLinkable(release);
  const sourceNote = sourceLinkable && release.source ? `Source: ${release.source}` : `Source: ${release.sourceName}`;
  const description = [release.synopsis?.trim(), sourceNote]
    .filter(Boolean)
    .join("\n\n")
    .slice(0, 1200);
  return [
    "BEGIN:VEVENT",
    `UID:ghastly-guide-${release.id}@ghastlyguide.com`,
    `DTSTAMP:${stamp}`,
    `DTSTART;VALUE=DATE:${icsDate(release.date)}`,
    `DTEND;VALUE=DATE:${icsDate(addDays(release.date, 1))}`,
    `SUMMARY:${icsEscape(release.title)}`,
    ...(description ? [`DESCRIPTION:${icsEscape(description)}`] : []),
    ...(sourceLinkable && release.source ? [`URL:${release.source}`] : []),
    "END:VEVENT",
  ];
}

function datedReleases(releases: Release[]): Release[] {
  return releases
    .filter(canAddToCalendar)
    .sort((a, b) => a.date.localeCompare(b.date) || a.title.localeCompare(b.title));
}

function downloadIcs(ics: string, filename: string): void {
  const blob = new Blob([ics], { type: "text/calendar;charset=utf-8" });
  const href = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = href;
  link.download = filename;
  document.body.append(link);
  link.click();
  link.remove();
  window.setTimeout(() => URL.revokeObjectURL(href), 1000);
}

export function calendarFilename(title: string): string {
  const slug = title.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "").slice(0, 48);
  return `${slug || "ghastly-guide-release"}.ics`;
}

export function canAddToCalendar(release: Pick<Release, "date" | "datePrecision">): boolean {
  return release.datePrecision === "day" && /^\d{4}-\d{2}-\d{2}$/.test(release.date);
}

export function releaseCalendarIcs(release: Release): string {
  return wrapCalendar(releaseEventLines(release, icsStamp()));
}

export function savedCalendarIcs(releases: Release[]): string {
  const events = datedReleases(releases);
  const stamp = icsStamp();
  return wrapCalendar([
    "X-WR-CALNAME:Ghastly Guide Saved",
    ...events.flatMap(release => releaseEventLines(release, stamp)),
  ]);
}

export function downloadReleaseCalendar(release: Release): void {
  if (!canAddToCalendar(release)) return;
  downloadIcs(releaseCalendarIcs(release), calendarFilename(release.title));
}

export function downloadSavedCalendar(releases: Release[]): void {
  const events = datedReleases(releases);
  if (!events.length) return;
  downloadIcs(savedCalendarIcs(events), "ghastly-guide-saved.ics");
}

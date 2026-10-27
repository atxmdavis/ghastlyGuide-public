export function isoToday(date = new Date()): string {
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: "America/Chicago",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(date);
}

export function addMonths(iso: string, count: number): string {
  const date = new Date(`${iso}T12:00:00Z`);
  date.setUTCMonth(date.getUTCMonth() + count);
  return date.toISOString().slice(0, 10);
}

export function addDays(iso: string, count: number): string {
  const date = new Date(`${iso}T12:00:00Z`);
  date.setUTCDate(date.getUTCDate() + count);
  return date.toISOString().slice(0, 10);
}

export function startOfMonth(iso: string): string {
  return `${iso.slice(0, 7)}-01`;
}

export function endOfMonth(iso: string): string {
  return addDays(addMonths(startOfMonth(iso), 1), -1);
}

export const datePresets = [
  { id: "today", label: "Today" },
  { id: "week", label: "This week" },
  { id: "month", label: "This month" },
  { id: "months3", label: "3 Months" },
  { id: "months6", label: "6 months" },
  { id: "year", label: "This year" },
] as const;

export type DatePreset = (typeof datePresets)[number]["id"];

export function rangeForPreset(id: DatePreset, today = isoToday()): { from: string; to: string } {
  if (id === "today") return { from: today, to: today };
  if (id === "week") return { from: today, to: addDays(today, 6) };
  if (id === "month") return { from: startOfMonth(today), to: endOfMonth(today) };
  if (id === "months3") {
    const start = startOfMonth(today);
    return { from: start, to: endOfMonth(addMonths(start, 2)) };
  }
  if (id === "months6") {
    const start = startOfMonth(today);
    return { from: start, to: addMonths(start, 6) };
  }
  const year = today.slice(0, 4);
  return { from: `${year}-01-01`, to: `${year}-12-31` };
}

export function presetForRange(from: string, to: string, today = isoToday()): DatePreset | "" {
  return datePresets.find(({ id }) => {
    const range = rangeForPreset(id, today);
    return range.from === from && range.to === to;
  })?.id ?? "";
}

export function formatDate(iso: string): string {
  return new Intl.DateTimeFormat("en-US", {
    month: "short",
    day: "numeric",
    year: "numeric",
    timeZone: "UTC",
  }).format(new Date(`${iso}T12:00:00Z`));
}

export function formatDateRange(from: string, to: string): string {
  return `${formatDate(from)} – ${formatDate(to)}`;
}

export function formatReleaseDate(
  iso: string,
  precision: "day" | "month" | "year" | "tbd",
): string {
  if (precision === "tbd") return "Date TBD";
  if (precision === "year") return iso.slice(0, 4);
  if (precision === "month") return monthLabel(iso);
  return formatDate(iso);
}

export function monthKey(iso: string): string {
  return iso.slice(0, 7);
}

export function monthLabel(iso: string): string {
  return new Intl.DateTimeFormat("en-US", {
    month: "long",
    year: "numeric",
    timeZone: "UTC",
  }).format(new Date(`${iso}T12:00:00Z`));
}

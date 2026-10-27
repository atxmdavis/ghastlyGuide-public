export function ratingValue(score: number, scale: number): string {
  return scale === 100 ? `${Math.round(score)}%` : `${score.toFixed(1)}/${Math.round(scale)}`;
}

export function ratingCount(count: number): string {
  return new Intl.NumberFormat("en-US", {
    notation: count >= 10_000 ? "compact" : "standard",
    maximumFractionDigits: 1,
  }).format(count);
}

export function ratingUpdated(updatedAt: string): string {
  return new Intl.DateTimeFormat("en-US", {
    month: "short",
    day: "numeric",
    year: "numeric",
  }).format(new Date(updatedAt));
}

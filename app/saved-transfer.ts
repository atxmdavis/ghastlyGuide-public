import { normalizeSaved, type SavedState } from "./saved";

type TransferCreateResponse = { token?: string; error?: string };
type TransferClaimResponse = { saves?: unknown; error?: string };

export function copyLinkFromToken(token: string, origin = window.location.origin): string {
  const url = new URL(origin);
  url.searchParams.set("saved", "1");
  url.searchParams.set("copy", token);
  url.hash = "full-calendar";
  return url.toString();
}

export function pendingCopyToken(search = window.location.search): string {
  return new URLSearchParams(search).get("copy")?.trim() ?? "";
}

export function clearCopyTokenFromUrl(): void {
  const url = new URL(window.location.href);
  if (!url.searchParams.has("copy")) return;
  url.searchParams.delete("copy");
  window.history.replaceState({}, "", `${url.pathname}${url.search}${url.hash}`);
}

export async function createSavedTransfer(saves: SavedState, signal?: AbortSignal): Promise<string> {
  const response = await fetch("/api/saved-transfer", {
    method: "POST",
    headers: { "Content-Type": "application/json", Accept: "application/json" },
    body: JSON.stringify({ saves }),
    signal,
  });
  const payload = await response.json() as TransferCreateResponse;
  if (!response.ok || !payload.token) throw new Error(payload.error || "Copying saves is temporarily unavailable.");
  return payload.token;
}

export async function claimSavedTransfer(token: string, signal?: AbortSignal): Promise<SavedState> {
  const response = await fetch("/api/saved-transfer", {
    method: "POST",
    headers: { "Content-Type": "application/json", Accept: "application/json" },
    body: JSON.stringify({ token }),
    signal,
  });
  const payload = await response.json() as TransferClaimResponse;
  if (!response.ok || !payload.saves) throw new Error(payload.error || "That copy link expired or was already used.");
  const result = normalizeSaved(payload.saves);
  if (!Object.keys(result.saves).length || result.warning) {
    throw new Error("That copy contains unreadable titles. The saved list on this device has not changed. Create a new copy from the other device.");
  }
  return result.saves;
}

let inflightToken = "";
let inflightClaim: Promise<SavedState> | null = null;

export function claimSavedTransferOnce(token: string): Promise<SavedState> {
  if (inflightToken === token && inflightClaim) return inflightClaim;
  inflightToken = token;
  inflightClaim = claimSavedTransfer(token);
  return inflightClaim;
}

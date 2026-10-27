import { useEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { copyLinkFromToken, createSavedTransfer } from "./saved-transfer";
import type { SavedState } from "./saved";

export default function SavedTransferModal({ saves, onClose }: { saves: SavedState; onClose: () => void }) {
  const count = Object.keys(saves).length;
  const [pairLink, setPairLink] = useState("");
  const [qrCode, setQrCode] = useState("");
  const [copyLabel, setCopyLabel] = useState("Copy pairing link");
  const [status, setStatus] = useState(count ? "Creating a private copy link…" : "Save a title on this device first.");
  const [error, setError] = useState(count === 0);
  const dialogRef = useRef<HTMLDivElement>(null);
  const closeRef = useRef<HTMLButtonElement>(null);
  const linkRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (!count) return;
    const controller = new AbortController();
    void createSavedTransfer(saves, controller.signal).then(token => {
      setPairLink(copyLinkFromToken(token));
      setStatus("This one-time link expires in 10 minutes. It copies the list onto one other device.");
      setError(false);
    }).catch(caught => {
      if (caught instanceof DOMException && caught.name === "AbortError") return;
      setError(true);
      setStatus(caught instanceof Error ? caught.message : "Copying saves is temporarily unavailable.");
    });
    return () => controller.abort();
  }, [count, saves]);

  useEffect(() => {
    if (!pairLink) return;
    void import("qrcode").then(({ default: QRCode }) => QRCode.toDataURL(pairLink, {
      width: 232,
      margin: 1,
      color: { dark: "#12100e", light: "#ffffff" },
    })).then(setQrCode).catch(() => {
      setStatus("The QR code could not be created. Copy the pairing link to your other device instead.");
    });
  }, [pairLink]);

  useEffect(() => {
    const previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    closeRef.current?.focus();
    function onKey(event: KeyboardEvent) {
      if (event.key === "Escape") {
        onClose();
        return;
      }
      if (event.key !== "Tab") return;
      const focusable = Array.from(dialogRef.current?.querySelectorAll<HTMLElement>("button, input, [href], [tabindex]:not([tabindex='-1'])") ?? [])
        .filter(element => !element.hasAttribute("disabled"));
      if (focusable.length === 0) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }
    document.addEventListener("keydown", onKey);
    return () => {
      document.body.style.overflow = previousOverflow;
      document.removeEventListener("keydown", onKey);
      previousFocus?.focus();
    };
  }, [onClose]);

  return createPortal(
    <div className="pair-dialog" role="presentation" onClick={onClose}>
      <div ref={dialogRef} className="pair-dialog__card" role="dialog" aria-modal="true" aria-labelledby="pair-dialog-title" onClick={event => event.stopPropagation()}>
        <button ref={closeRef} className="pair-dialog__close" type="button" aria-label="Close" onClick={onClose}>×</button>
        <p className="pair-dialog__eyebrow">Copy to another device</p>
        <h3 id="pair-dialog-title">Scan on your other device</h3>
        <p role="status">{status}</p>
        {pairLink && !error ? <>
          {qrCode ? <img src={qrCode} width="232" height="232" alt="QR code containing the one-time saved-titles link" /> : <div className="pair-dialog__qr-placeholder" aria-label="Creating QR code" />}
          <label className="pair-dialog__link"><span>Pairing link</span><input ref={linkRef} aria-label="Pairing link" value={pairLink} readOnly onFocus={event => event.currentTarget.select()} /></label>
          <button className="pair-dialog__copy" type="button" onClick={async () => {
            try {
              await navigator.clipboard.writeText(pairLink);
              setCopyLabel("Link copied");
            } catch {
              linkRef.current?.focus();
              linkRef.current?.select();
              setCopyLabel("Link selected — press copy");
            }
            window.setTimeout(() => setCopyLabel("Copy pairing link"), 1800);
          }}>{copyLabel}</button>
          <p className="pair-dialog__fineprint">Only share this link with a device you trust. Still no account or login—just a one-time copy. After that, the two lists stay separate until you copy again.</p>
        </> : null}
      </div>
    </div>,
    document.body,
  );
}

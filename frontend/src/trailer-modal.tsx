import { useEffect, useRef } from "react";
import { createPortal } from "react-dom";
import { youtubeVideoId } from "./youtube";

export default function TrailerModal({ title, url, onClose }: { title: string; url: string; onClose: () => void }) {
  const videoId = youtubeVideoId(url);
  const dialogRef = useRef<HTMLDialogElement>(null);
  const closeRef = useRef<HTMLButtonElement>(null);
  const previousFocus = useRef<HTMLElement | null>(null);
  useEffect(() => {
    previousFocus.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    const dialog = dialogRef.current;
    dialog?.showModal();
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    closeRef.current?.focus();
    return () => {
      dialog?.close();
      document.body.style.overflow = previousOverflow;
      previousFocus.current?.focus();
    };
  }, [onClose]);
  if (!videoId) return null;
  return createPortal(
    <dialog ref={dialogRef} className="trailer-modal" aria-labelledby="trailer-title" onCancel={event => { event.preventDefault(); onClose(); }} onClick={event => { if (event.target === event.currentTarget) onClose(); }}>
      <div className="trailer-modal__dialog" onClick={event => event.stopPropagation()}>
        <div className="trailer-modal__header">
          <h2 id="trailer-title">{title}</h2>
          <button type="button" className="trailer-modal__close" ref={closeRef} onClick={onClose} aria-label="Close trailer">×</button>
        </div>
        <div className="trailer-modal__frame">
          <iframe
            title={`${title} trailer`}
            src={`https://www.youtube-nocookie.com/embed/${videoId}?autoplay=0&rel=0`}
            allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
            allowFullScreen
          />
        </div>
      </div>
    </dialog>,
    document.body,
  );
}

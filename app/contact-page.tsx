import { FormEvent, useState } from "react";
import SiteFooter from "./site-footer";
import SiteHeader from "./site-header";

type FormStatus = "idle" | "sending" | "sent" | "error";

export default function ContactPage() {
  const [status, setStatus] = useState<FormStatus>("idle");
  const [feedback, setFeedback] = useState("");

  async function submitContact(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setStatus("sending");
    setFeedback("");
    const form = event.currentTarget;
    const data = new FormData(form);

    try {
      const response = await fetch("/api/contact", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          name: data.get("name"),
          email: data.get("email"),
          message: data.get("message"),
          website: data.get("website"),
        }),
      });
      const payload = await response.json() as { message?: string };
      if (!response.ok) throw new Error(payload.message || "Your message could not be sent.");
      form.reset();
      setStatus("sent");
      setFeedback(payload.message || "Your message has been sent.");
    } catch (error) {
      setStatus("error");
      setFeedback(error instanceof Error ? error.message : "Your message could not be sent.");
    }
  }

  return <>
    <main id="main-content" tabIndex={-1}>
    <SiteHeader active="contact" heading={<>
      <p className="eyebrow">Send a signal</p>
      <h1>Speak with <em>the living.</em></h1>
    </>} />
    <section className="source-policy">
      <div className="source-policy__intro">
        <p>Found a missing release, a changed date, or something else we should know? Send it through. We read these. We do not turn a rumor into a card.</p>
        <h2>What helps most</h2>
        <p>A title, the medium, and a public page we can check—a studio, publisher, platform, or licensed catalog. A screenshot from a group chat is not a source.</p>
        <p>If a date on the calendar looks wrong, name the title and send the link that disagrees. We will not replace an official date with a guess.</p>
        <p>Missing a horror film, game, comic, book, show, or tabletop release that actually has a date? Suggest it here. If we cannot point to where the date came from, it does not ship. The sourcing page explains that rule in full.</p>
        <p><a href="/sources">How we curate <span aria-hidden="true">→</span></a></p>
        <h2>We will write back</h2>
        <p>Leave a name and email so we can follow up. Those details are used only to respond to this message. There is still no account.</p>
      </div>
      <form className="contact-form" onSubmit={submitContact}>
        <label>Name<input name="name" type="text" autoComplete="name" maxLength={100} required /></label>
        <label>Email<input name="email" type="email" autoComplete="email" maxLength={254} required /></label>
        <label>Message<textarea name="message" rows={8} minLength={10} maxLength={5000} required /></label>
        <label className="contact-honeypot" aria-hidden="true">Website<input name="website" type="text" autoComplete="off" tabIndex={-1} /></label>
        <div className="contact-form__footer">
          <p>Your details are used only to respond to this message.</p>
          <button type="submit" disabled={status === "sending"}>{status === "sending" ? "Sending…" : "Send message"}</button>
        </div>
        {feedback ? <p className={`contact-form__status contact-form__status--${status}`} role={status === "error" ? "alert" : "status"}>{feedback}</p> : null}
      </form>
    </section>
    <SiteFooter />
    </main>
  </>;
}

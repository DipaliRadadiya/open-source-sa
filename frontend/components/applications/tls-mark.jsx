import { ShieldCheck, ShieldOff } from "lucide-react";
import { cn } from "@/lib/utils";

/**
 * Whether this site is actually served over TLS.
 *
 * Read from `url`, which the SERVER decides — it is `https` only when the
 * certificate is both servable and covers this domain:
 *
 *     $this->certificate?->servable()
 *         && $this->certificate->covers((string) $this->domain) ? 'https' : 'http'
 *
 * That is a stronger claim than "a certificate row exists", and it is the
 * reason this reads the scheme instead of looking for a certificate field.
 * There is no certificate field on the list payload, and inventing one from
 * `basic_auth_enabled`-style guesswork would answer a different question.
 *
 * Scoped to the PRIMARY domain, because that is all `url` describes. A site
 * with three domains where two have no certificate still shows secure here —
 * the per-domain truth lives on Domains & SSL, which is where the mark links
 * people who want it.
 */
export function isServedOverTls(application) {
  return String(application?.url ?? "").startsWith("https://");
}

/**
 * The padlock next to a domain.
 *
 * Icons and tones are lifted from `domains-card.jsx` rather than chosen again,
 * so one site does not get a green shield on its detail page and a different
 * mark in the list. Notably that card uses `secondary`, NOT destructive, for
 * "no certificate" — every site lacks one for the first minutes of its life
 * and a list of ten red rows on a fresh server would be shouting about a state
 * that resolves itself.
 *
 * An icon, not the card's full Badge: this repeats once per row, and a labelled
 * pill per row would out-weigh the site names it sits under.
 *
 * Renders NOTHING when there is no `url`. A site still provisioning has no
 * answer yet, and "no certificate" is a claim, not a blank.
 */
export function TlsMark({ application, label, className }) {
  if (!application?.url) return null;

  const secure = isServedOverTls(application);
  const Icon = secure ? ShieldCheck : ShieldOff;

  return (
    <Icon
      role="img"
      aria-label={label}
      title={label}
      className={cn(
        "size-3.5 shrink-0",
        secure ? "text-success" : "text-muted-foreground",
        className,
      )}
    />
  );
}

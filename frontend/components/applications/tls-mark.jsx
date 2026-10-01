import { ShieldCheck, ShieldOff } from "lucide-react";
import { cn } from "@/lib/utils";

/**
 * Whether the site is actually served over TLS, read from the scheme of `url`.
 * The server sets `https` only when the certificate is servable and covers the
 * domain; the list payload has no certificate field.
 *
 * Scoped to the primary domain (all `url` describes); per-domain status lives
 * on Domains & SSL.
 */
export function isServedOverTls(application) {
  return String(application?.url ?? "").startsWith("https://");
}

/**
 * The padlock next to a domain. Icons and tones match `domains-card.jsx`; a
 * missing certificate is not destructive, since every new site lacks one for
 * its first minutes. An icon, not a badge, since it repeats per row.
 *
 * Renders nothing without a `url`: a provisioning site has no answer yet.
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

import { ExternalLink } from "lucide-react";
import { cn } from "@/lib/utils";

/**
 * Open a site in a new tab, from wherever its name is shown.
 *
 * The scheme is never assumed: without a certificate there is no TLS listener,
 * and a certificate covers named hostnames only. Callers pass `secure` from
 * evidence (the app's `url` for the primary name, `certificate.domains` for
 * others).
 *
 * Icon-only beside the domain; the accessible name carries the hostname.
 */
export function VisitSiteLink({ href, domain, secure = false, label, className }) {
  const url = href ?? `${secure ? "https" : "http"}://${domain}`;

  return (
    <a
      href={url}
      target="_blank"
      rel="noreferrer"
      // The surrounding row or card is often a link; keep the click from reaching it.
      onClick={(event) => event.stopPropagation()}
      aria-label={label}
      title={label}
      className={cn(
        "inline-flex size-6 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none",
        className,
      )}
    >
      <ExternalLink className="size-3.5" />
    </a>
  );
}

import { ExternalLink } from "lucide-react";
import { cn } from "@/lib/utils";

/**
 * Open a site in a new tab, from wherever its name is shown.
 *
 * "Visit site" existed only on the application dashboard, so seeing the site you
 * were editing meant navigating back to that one screen first — from Domains,
 * from SSL, from anywhere. The name of a site is the obvious place to click, and
 * it was inert everywhere except one page.
 *
 * The scheme is decided, never assumed. A site with no certificate has no TLS
 * listener at all, so an assumed https:// is a connection refused — and a
 * certificate covers named hostnames, not every alias a site answers to. So the
 * caller passes `secure` from real evidence: the application's own `url` field
 * for the primary name, or whether `certificate.domains` contains this exact
 * hostname for the rest.
 *
 * Icon-only, because it sits beside a domain that is already the label. The
 * accessible name carries the hostname so a screen reader hears which site it
 * opens rather than "link, link, link".
 */
export function VisitSiteLink({ href, domain, secure = false, label, className }) {
  const url = href ?? `${secure ? "https" : "http"}://${domain}`;

  return (
    <a
      href={url}
      target="_blank"
      rel="noreferrer"
      // The row or card around this is usually itself a link to somewhere in
      // the panel; without this the click would navigate there instead.
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

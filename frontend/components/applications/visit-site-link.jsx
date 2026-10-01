import { ExternalLink } from "lucide-react";
import { cn } from "@/lib/utils";

// The scheme is never assumed: no certificate means no TLS listener, and certificates cover named hosts only.
// Callers pass `secure` from evidence (the app's `url`, or `certificate.domains`).
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

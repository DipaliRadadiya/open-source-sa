import { ExternalLink } from "lucide-react";
import { cn } from "@/lib/utils";
import { HoverHint } from "@/components/ui/hover-hint";

// Never assume https: no certificate means no TLS listener.
export function VisitSiteLink({ href, domain, secure = false, label, className }) {
  const url = href ?? `${secure ? "https" : "http"}://${domain}`;

  return (
    <HoverHint content={label}><a
      href={url}
      target="_blank"
      rel="noreferrer"
      onClick={(event) => event.stopPropagation()}
      aria-label={label}
      className={cn(
        "inline-flex size-6 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none",
        className,
      )}
    >
      <ExternalLink className="size-3.5" />
    </a></HoverHint>
  );
}

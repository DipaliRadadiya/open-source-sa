import { cn } from "@/lib/utils";

// The masthead of a detail page (one database, one role…), drawn like the application
// page's: an icon tile, the name with its badges, one line of facts, actions on the right.
// It is the page's h1, so the breadcrumb is the only way back; no "Back to" link.
export function DetailHeader({ icon, title, mono = false, badges = null, facts = [], actions = null }) {
  const shown = facts.filter(Boolean);
  return (
    <div className="relative overflow-hidden rounded-2xl border bg-card px-5 py-4 shadow-e1">
      <div
        aria-hidden
        className="pointer-events-none absolute inset-0 bg-[radial-gradient(70%_140%_at_100%_0%,color-mix(in_oklch,var(--primary)_10%,transparent),transparent_60%)]"
      />
      <div className="relative flex flex-wrap items-center justify-between gap-4">
        <div className="flex min-w-0 items-center gap-3.5">
          <span className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary [&_svg]:size-6">
            {icon}
          </span>
          <div className="min-w-0 space-y-1">
            <div className="flex flex-wrap items-center gap-2">
              <h1 className={cn("min-w-0 text-xl font-semibold tracking-tight break-words", mono && "font-mono")}>
                {title}
              </h1>
              {badges}
            </div>
            {shown.length ? (
              <div className="flex flex-wrap items-center gap-x-2.5 gap-y-1 text-xs text-muted-foreground">
                {shown.map((fact, index) => (
                  <span key={index} className="flex items-center gap-2.5">
                    {index > 0 ? <span aria-hidden className="size-1 rounded-full bg-border" /> : null}
                    {fact}
                  </span>
                ))}
              </div>
            ) : null}
          </div>
        </div>
        {actions ? <div className="flex flex-wrap items-center gap-2">{actions}</div> : null}
      </div>
    </div>
  );
}

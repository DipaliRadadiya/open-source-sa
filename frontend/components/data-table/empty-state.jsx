import { cn } from "@/lib/utils";

/**
 * Reusable empty-state card for list pages. `icon` is a Lucide component;
 * `action` is an optional node (e.g. a CTA button).
 *
 * `compact` is for an empty state that sits INSIDE another card rather than
 * filling a page. At the page size it is mostly air by design — you have
 * arrived somewhere and there is nothing here. Inside a card that already has
 * a heading and a description above it, that same 64px of padding is a second
 * empty box nested in the first, which is how the dashboard's three-row
 * process card came to be taller than the charts above it.
 *
 * Opt-in, not a breakpoint: 33 screens use this and the page-level size is
 * right on all of them.
 */
export function EmptyState({ icon: Icon, title, description, action, compact = false }) {
  return (
    // px-6: with padding on the vertical axis only, the description ran to
    // within a pixel of the dashed border on a phone and read as broken rather
    // than centred. py stays larger than px — an empty state is mostly air.
    <div
      className={cn(
        "flex flex-col items-center justify-center rounded-xl text-center",
        // Solid and filled when compact, dashed when it fills a page.
        //
        // A dashed rectangle is the universal "drop a file here / not built
        // yet" placeholder. On a page that is the right note — there is nothing
        // here and you are meant to put something here. Inside a finished card
        // it reads as scaffolding someone forgot to remove, which is how the
        // process card looked once the 24h cards beside it had stopped being
        // dashed and it was the only one left.
        compact ? "gap-2 bg-muted/40 px-6 py-8" : "gap-3 border border-dashed px-6 py-16",
      )}
    >
      <span
        className={cn(
          "flex items-center justify-center rounded-full text-muted-foreground",
          // On a filled well the icon needs to come forward, not sink further
          // in — bg-muted on bg-muted/40 is the same disc with no edge.
          compact ? "size-8 bg-background shadow-e1" : "size-12 bg-muted",
        )}
      >
        <Icon className={compact ? "size-4" : "size-5"} />
      </span>
      {/* The description is optional, and rendering it unconditionally is why
          it looked mandatory.
          
          Every filtered empty state here carries a Clear-search button, so a
          middle line reading "No applications match your search" under a title
          reading "No matching applications" is the same sentence three times.
          Where the line names WHICH filters are in play it earns its space;
          where it only rephrases the title it does not, and four call sites now
          pass nothing. Without this guard that left an empty <p> holding
          space-y-1 open. */}
      <div className="space-y-1">
        <p className={cn("font-medium", compact && "text-sm")}>{title}</p>
        {description ? (
          <p className="max-w-sm text-sm text-pretty text-muted-foreground">{description}</p>
        ) : null}
      </div>
      {action}
    </div>
  );
}

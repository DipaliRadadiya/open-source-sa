import { cn } from "@/lib/utils";
import { Skeleton } from "@/components/ui/skeleton";

// Building blocks for loading screens. Each one mirrors a real piece of the panel
// (page header, card, tiles, rows, form, chart…) so a loading screen can be put
// together in the same shape as the page it stands in for, and nothing jumps.

const CARD = "rounded-2xl border border-border/70 bg-card shadow-e1";

export function SkHeader({ back = false, wide = false }) {
  return (
    <div className="space-y-2">
      {back ? <Skeleton className="mb-3 h-4 w-32" /> : null}
      <Skeleton className="h-7 w-44" />
      <Skeleton className={cn("h-4 max-w-full", wide ? "w-[28rem]" : "w-80")} />
    </div>
  );
}

// A card with the panel's card head (title + one line, an optional button on the right).
export function SkCard({ className, head = true, action = false, children, bodyClassName }) {
  return (
    <div className={cn(CARD, "overflow-hidden", className)} aria-hidden>
      {head ? (
        // Bottom padding too when the card has no body, or the bars sat on the border (7 Oct).
        <div className={cn("flex items-start justify-between gap-4 px-5 pt-5", !children && "pb-5")}>
          <div className="space-y-2">
            <Skeleton className="h-4 w-36" />
            <Skeleton className="h-3 w-64 max-w-full" />
          </div>
          {action ? <Skeleton className="h-9 w-32 rounded-lg" /> : null}
        </div>
      ) : null}
      {children ? <div className={cn("p-5", head && "pt-4", bodyClassName)}>{children}</div> : null}
    </div>
  );
}

// The grey strip with pills, as Tabs.
export function SkTabs({ count = 3 }) {
  return (
    <div className="inline-flex gap-1 rounded-lg bg-muted p-1" aria-hidden>
      {Array.from({ length: count }).map((_, i) => (
        <div key={i} className={cn("flex h-8 items-center rounded-md px-3", i === 0 && "bg-card shadow-sm")}>
          <Skeleton className="h-3 w-16" />
        </div>
      ))}
    </div>
  );
}

// Status tiles: an icon tile, a label, a value and a line, as on the application page.
export function SkTiles({ count = 4, className }) {
  return (
    <div className={cn("grid gap-4 sm:grid-cols-2 xl:grid-cols-4", className)} aria-hidden>
      {Array.from({ length: count }).map((_, i) => (
        <div key={i} className={cn(CARD, "space-y-3 p-4")}>
          <div className="flex items-center gap-3">
            <Skeleton className="size-9 rounded-xl" />
            <Skeleton className="h-3 w-20" />
          </div>
          <Skeleton className="h-4 w-24" />
          <Skeleton className="h-3 w-36" />
        </div>
      ))}
    </div>
  );
}

// Divided rows, edge to edge inside a card body: an icon, two lines, something on the right.
export function SkRows({ count = 4, icon = true, trailing = "pill" }) {
  return (
    <div className="-mx-5 -mb-5 divide-y border-t" aria-hidden>
      {Array.from({ length: count }).map((_, i) => (
        <div key={i} className="flex items-center gap-3 px-5 py-3.5">
          {icon ? <Skeleton className="size-8 shrink-0 rounded-lg" /> : null}
          <div className="min-w-0 flex-1 space-y-2">
            <Skeleton className={cn("h-3.5", ["w-40", "w-56", "w-48", "w-36"][i % 4])} />
            <Skeleton className={cn("h-3", ["w-64", "w-52", "w-72", "w-44"][i % 4])} />
          </div>
          {trailing === "pill" ? <Skeleton className="h-6 w-16 rounded-full" /> : null}
          {trailing === "switch" ? <Skeleton className="h-5 w-9 rounded-full" /> : null}
          {trailing === "button" ? <Skeleton className="h-8 w-24 rounded-lg" /> : null}
        </div>
      ))}
    </div>
  );
}

// Setting rows: a label and hint on the left, the control on the right.
export function SkSettingRows({ count = 3 }) {
  return (
    <div className="divide-y" aria-hidden>
      {Array.from({ length: count }).map((_, i) => (
        <div key={i} className="grid gap-x-8 gap-y-2 py-3.5 first:pt-0 sm:grid-cols-[minmax(0,1fr)_14rem] sm:items-center">
          <div className="space-y-1.5">
            <Skeleton className="h-3.5 w-40" />
            <Skeleton className="h-3 w-64 max-w-full" />
          </div>
          <SkInput />
        </div>
      ))}
    </div>
  );
}

export function SkInput({ className }) {
  return <div className={cn("h-9 rounded-lg border border-input bg-background", className)} aria-hidden />;
}

// A form: label over input, in one or two columns.
export function SkForm({ fields = 2, columns = 2 }) {
  return (
    <div className={cn("grid gap-5", columns === 2 && "sm:grid-cols-2")} aria-hidden>
      {Array.from({ length: fields }).map((_, i) => (
        <div key={i} className="space-y-2">
          <Skeleton className="h-3.5 w-24" />
          <SkInput />
        </div>
      ))}
    </div>
  );
}

// The band at the foot of a card that holds Save.
export function SkFooter() {
  return (
    <div className="-mx-5 -mb-5 mt-5 flex justify-end border-t bg-muted/30 px-5 py-3" aria-hidden>
      <Skeleton className="h-9 w-28 rounded-lg" />
    </div>
  );
}

// A tinted note (info / warning / healthy), as the real ones.
const NOTE = {
  info: "border-primary/15 bg-primary/5",
  warning: "border-warning/25 bg-warning/5",
  success: "border-success/25 bg-success/5",
  destructive: "border-destructive/20 bg-destructive/5",
};
export function SkNote({ tone = "info", className, button = false }) {
  return (
    <div className={cn("flex items-center gap-3 rounded-xl border px-4 py-3", NOTE[tone], className)} aria-hidden>
      <Skeleton className="size-5 shrink-0 rounded-md" />
      <div className="min-w-0 flex-1 space-y-2">
        <Skeleton className="h-3.5 w-56 max-w-full" />
        <Skeleton className="h-3 w-96 max-w-full" />
      </div>
      {button ? <Skeleton className="h-8 w-28 rounded-lg" /> : null}
    </div>
  );
}

// A chart area: faint grid lines and a soft wave where the line will be.
export function SkChart({ className }) {
  return (
    <div className={cn("relative h-52 overflow-hidden rounded-lg", className)} aria-hidden>
      <div className="absolute inset-0 flex flex-col justify-between py-2">
        {Array.from({ length: 5 }).map((_, i) => (
          <div key={i} className="border-t border-dashed border-border/70" />
        ))}
      </div>
      <svg viewBox="0 0 400 100" preserveAspectRatio="none" className="absolute inset-x-0 bottom-0 h-3/4 w-full">
        <path
          d="M0 70 C40 60 60 30 100 40 S160 75 200 55 S260 20 300 35 S360 60 400 45 L400 100 L0 100 Z"
          className="skeleton-shimmer-fill"
        />
      </svg>
    </div>
  );
}

// Dark log / editor panel with line placeholders.
export function SkTerminal({ lines = 10, className }) {
  return (
    <div className={cn("space-y-2.5 rounded-xl bg-zinc-950 p-4", className)} aria-hidden>
      {Array.from({ length: lines }).map((_, i) => (
        <div key={i} className={cn("h-3 rounded bg-white/10", ["w-11/12", "w-4/5", "w-3/4", "w-5/6", "w-2/3"][i % 5])} />
      ))}
    </div>
  );
}

// A centred empty-state block inside a card.
export function SkCentered() {
  return (
    <div className="flex flex-col items-center gap-3 py-8" aria-hidden>
      <Skeleton className="size-14 rounded-2xl" />
      <Skeleton className="h-4 w-48" />
      <Skeleton className="h-3 w-72 max-w-full" />
      <Skeleton className="mt-2 h-9 w-36 rounded-lg" />
    </div>
  );
}

// Choice cards (radio options), as on AI Bot Blocker or Create application.
export function SkOptions({ count = 3, columns = 1 }) {
  return (
    <div className={cn("grid gap-3", columns === 2 && "sm:grid-cols-2", columns === 3 && "sm:grid-cols-3")} aria-hidden>
      {Array.from({ length: count }).map((_, i) => (
        <div key={i} className="flex items-start gap-3 rounded-xl border p-4">
          <Skeleton className="size-8 shrink-0 rounded-lg" />
          <div className="min-w-0 flex-1 space-y-2">
            <Skeleton className="h-3.5 w-40" />
            <Skeleton className="h-3 w-full max-w-80" />
          </div>
        </div>
      ))}
    </div>
  );
}

// A grid of facts: a small label over a value.
export function SkFacts({ count = 4, className }) {
  return (
    <div className={cn("grid gap-4 sm:grid-cols-2 xl:grid-cols-4", className)} aria-hidden>
      {Array.from({ length: count }).map((_, i) => (
        <div key={i} className="space-y-2">
          <Skeleton className="h-3 w-16" />
          <Skeleton className={cn("h-4", ["w-28", "w-20", "w-36", "w-24"][i % 4])} />
        </div>
      ))}
    </div>
  );
}

// The masthead of a detail page: an icon tile, name, facts, buttons.
export function SkMasthead({ buttons = 2 }) {
  return (
    <div className={cn(CARD, "flex flex-wrap items-center justify-between gap-4 px-5 py-4")} aria-hidden>
      <div className="flex min-w-0 items-center gap-3.5">
        <Skeleton className="size-12 shrink-0 rounded-xl" />
        <div className="space-y-2">
          <Skeleton className="h-6 w-48" />
          <Skeleton className="h-3.5 w-72 max-w-full" />
        </div>
      </div>
      <div className="flex gap-2">
        {Array.from({ length: buttons }).map((_, i) => (
          <Skeleton key={i} className="h-9 w-28 rounded-lg" />
        ))}
      </div>
    </div>
  );
}

// A summary card: a status tile, two lines, buttons on the right (System health, Sync…).
export function SkSummary({ tone, buttons = 1 }) {
  return (
    <div className={cn(CARD, "flex flex-wrap items-center gap-4 p-4", tone && NOTE[tone])} aria-hidden>
      <Skeleton className="size-11 shrink-0 rounded-xl" />
      <div className="min-w-0 flex-1 space-y-2">
        <Skeleton className="h-4 w-40" />
        <Skeleton className="h-3 w-72 max-w-full" />
      </div>
      {Array.from({ length: buttons }).map((_, i) => (
        <Skeleton key={i} className="h-9 w-28 rounded-lg" />
      ))}
    </div>
  );
}

export { CARD as SK_CARD };

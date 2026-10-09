import { cn } from "@/lib/utils"

// A bar. Darker than the surface under it and with a light sweep, so it reads on the
// tinted page as well as inside a white card.
function Skeleton({
  className,
  ...props
}) {
  return (
    <div
      data-slot="skeleton"
      // max-w-full: fixed desktop widths (w-96…) must not overflow a phone.
      className={cn("skeleton-shimmer max-w-full rounded-md", className)}
      {...props} />
  );
}

// A whole card still loading: the real card's white surface, edge and shadow with a
// title and a few lines in it. A grey block the size of a card read as part of the page.
function SkeletonCard({ className, lines = 8, ...props }) {
  return (
    <div
      data-slot="skeleton-card"
      aria-hidden
      className={cn(
        "flex max-w-full flex-col gap-3 overflow-hidden rounded-2xl border border-border/70 bg-card p-5 shadow-e1",
        className
      )}
      {...props}>
      {/* shrink-0: in a short fixed-height card the bars were squeezed to nothing. */}
      <Skeleton className="h-4 w-1/3 max-w-48 shrink-0" />
      <Skeleton className="h-3 w-1/2 max-w-72 shrink-0" />
      {Array.from({ length: lines }).map((_, i) => (
        <Skeleton key={i} className={cn("h-3 shrink-0", ["mt-3 w-11/12", "w-4/5", "w-2/3", "w-3/4"][i % 4])} />
      ))}
    </div>
  );
}

export { Skeleton, SkeletonCard }

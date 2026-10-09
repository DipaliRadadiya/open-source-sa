import { cn } from "@/lib/utils";

// One card per list, as on Applications: the toolbar on top, the rows (or the empty
// state) in the middle, the pager underneath. Tables inside take `bare`.
//
// `from` is for lists that switch to cards of their own on narrow screens: below that
// width there is no frame (cards inside a card), and the three parts stack with a gap.
// Static class strings, because Tailwind only sees whole class names.
const FRAMES = {
  always: {
    root: "overflow-hidden rounded-2xl border border-border/70 bg-card shadow-e1",
    head: "border-b p-3",
    foot: "border-t px-4 py-3",
  },
  lg: {
    root: "list-card-lg space-y-4 lg:space-y-0 lg:overflow-hidden lg:rounded-2xl lg:border lg:border-border/70 lg:bg-card lg:shadow-e1",
    head: "lg:border-b lg:p-3",
    foot: "lg:border-t lg:px-4 lg:py-3",
  },
  xl: {
    root: "list-card-xl space-y-4 xl:space-y-0 xl:overflow-hidden xl:rounded-2xl xl:border xl:border-border/70 xl:bg-card xl:shadow-e1",
    head: "xl:border-b xl:p-3",
    foot: "xl:border-t xl:px-4 xl:py-3",
  },
  // A container query, for lists whose cards/table switch is measured on the content width.
  c1000: {
    root: "list-card-c1000 space-y-4 @min-[1000px]:space-y-0 @min-[1000px]:overflow-hidden @min-[1000px]:rounded-2xl @min-[1000px]:border @min-[1000px]:border-border/70 @min-[1000px]:bg-card @min-[1000px]:shadow-e1",
    head: "@min-[1000px]:border-b @min-[1000px]:p-3",
    foot: "@min-[1000px]:border-t @min-[1000px]:px-4 @min-[1000px]:py-3",
  },
  // The Backups overview: its table fits from 900px of content.
  c900: {
    root: "list-card-c900 space-y-4 @min-[900px]:space-y-0 @min-[900px]:overflow-hidden @min-[900px]:rounded-2xl @min-[900px]:border @min-[900px]:border-border/70 @min-[900px]:bg-card @min-[900px]:shadow-e1",
    head: "@min-[900px]:border-b @min-[900px]:p-3",
    foot: "@min-[900px]:border-t @min-[900px]:px-4 @min-[900px]:py-3",
  },
  wide: {
    root: "list-card-wide space-y-4 min-[1440px]:space-y-0 min-[1440px]:overflow-hidden min-[1440px]:rounded-2xl min-[1440px]:border min-[1440px]:border-border/70 min-[1440px]:bg-card min-[1440px]:shadow-e1",
    head: "min-[1440px]:border-b min-[1440px]:p-3",
    foot: "min-[1440px]:border-t min-[1440px]:px-4 min-[1440px]:py-3",
  },
};

export function ListCard({ toolbar = null, footer = null, from = "always", children, className }) {
  const frame = FRAMES[from];
  const card = (
    <div
      // An EmptyState inside drops its own frame: always in a full card, and from the
      // breakpoint in a responsive one (see the list-card-* markers it checks for).
      data-slot={from === "always" ? "card" : undefined}
      className={cn(frame.root, className)}
    >
      {toolbar ? <div className={frame.head}>{toolbar}</div> : null}
      {children}
      {/* The pager renders nothing on a single page; the band goes with it. */}
      {footer ? <div className={cn(frame.foot, "empty:hidden")}>{footer}</div> : null}
    </div>
  );
  return from === "c1000" || from === "c900" ? <div className="@container">{card}</div> : card;
}

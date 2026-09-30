import { TriangleAlert } from "lucide-react";
import { cn } from "@/lib/utils";

/**
 * A consequence the reader has to know before they act, said next to the thing
 * that causes it.
 *
 * Amber by default rather than red: nothing is wrong and they may well mean it
 * — they just have to know beforehand rather than after the next run. `tone`
 * opens the red case for the places where something IS wrong; without it three
 * files hand-rolled their own red version and each one put the whole sentence
 * in `text-destructive`, which is how a note turns into a wall of red.
 *
 * Colour lives on the icon and the surface. The prose stays ordinary
 * foreground text — a coloured sentence is harder to read, not more urgent.
 *
 * ⚠️ Red sits at 5% where amber sits at 10%, deliberately not the same number.
 * At equal opacity the red block reads far hotter, because the hue is doing
 * most of the shouting.
 *
 * `size="md"` is for a note that carries a button or more than one sentence;
 * the default stays small so the five screens that already use this are
 * untouched.
 */
const SURFACE = {
  // Soft (Krishna, 2026-09-29: "less visually heavy"): a tint and a hairline,
  // the icon carries the colour.
  warning: "border-warning/25 bg-warning/5",
  destructive: "border-destructive/25 bg-destructive/[0.03]",
};
const MARK = { warning: "text-warning", destructive: "text-destructive" };
const SIZE = {
  sm: { box: "gap-2 px-2.5 py-2 text-xs", icon: "mt-px size-3.5" },
  md: { box: "gap-2.5 px-3 py-2.5 text-sm", icon: "mt-0.5 size-4" },
};

export function Caution({
  tone = "warning",
  size = "sm",
  icon: Icon = TriangleAlert,
  action = null,
  children,
  className,
}) {
  const scale = SIZE[size] ?? SIZE.sm;
  return (
    // A <div>, not a <p>: callers pass sentences, but some pass a button or a
    // second paragraph, and a <p> wrapping those is invalid. Same call, same
    // reason, as `ui/note.jsx`.
    <div
      className={cn(
        "flex rounded-lg border",
        // With an action the row centres (one line of text beside a button)
        // and wraps: the text keeps a real width and the button drops below
        // it on a phone, instead of the text shrinking to a word per line.
        action ? "flex-wrap items-center" : "items-start",
        scale.box,
        SURFACE[tone] ?? SURFACE.warning,
        className,
      )}
    >
      <Icon className={cn("shrink-0", scale.icon, action && "mt-0 self-start sm:self-center", MARK[tone] ?? MARK.warning)} aria-hidden />
      {/* max-w-prose so a long consequence wraps at a readable measure rather
          than running the full width of a very wide card — but not beside a
          button: there it wrapped a one-line notice at 60% of the row and left
          the space next to it empty. */}
      <div className={cn("flex-1 space-y-2", action ? "min-w-48" : "min-w-0 [&>p]:max-w-prose")}>{children}</div>
      {/* A secondary action sits at the end of the line, not under the text,
          so the notice stays one row where there is room. */}
      {action ? <div className="shrink-0">{action}</div> : null}
    </div>
  );
}

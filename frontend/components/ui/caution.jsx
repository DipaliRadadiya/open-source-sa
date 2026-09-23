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
  warning: "border-warning/40 bg-warning/10",
  destructive: "border-destructive/40 bg-destructive/[0.05]",
};
const MARK = { warning: "text-warning", destructive: "text-destructive" };
const SIZE = {
  sm: { box: "gap-2 p-2.5 text-xs", icon: "mt-px size-3.5" },
  md: { box: "gap-2.5 p-3 text-sm", icon: "mt-0.5 size-4" },
};

export function Caution({
  tone = "warning",
  size = "sm",
  icon: Icon = TriangleAlert,
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
        "flex items-start rounded-lg border",
        scale.box,
        SURFACE[tone] ?? SURFACE.warning,
        className,
      )}
    >
      <Icon className={cn("shrink-0", scale.icon, MARK[tone] ?? MARK.warning)} aria-hidden />
      {/* max-w-prose so a long consequence wraps at a readable measure rather
          than running the full width of a very wide card. */}
      <div className="min-w-0 flex-1 space-y-2 [&>p]:max-w-prose">{children}</div>
    </div>
  );
}

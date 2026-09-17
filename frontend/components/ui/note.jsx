import { Info } from "lucide-react";
import { cn } from "@/lib/utils";

/**
 * What a feature does, said next to it — the calm sibling of `Caution`.
 *
 * Same grammar as that component on purpose: tinted surface, matching border,
 * coloured icon. Amber there means "know this before you act"; the accent here
 * means "here is what this is". Two tones, one shape, so a reader learns the
 * pattern once.
 *
 * It exists because four screens had each hand-rolled their own version — grey
 * text in a `bg-muted/40` box, two of them not even agreeing on whether to show
 * an icon. Reported as "this all even not looks like notes information", and
 * that was the whole of it: a grey block on a white page reads as empty space,
 * not as something worth reading.
 *
 * `icon` takes the feature's own mark — a shield for the firewall, a bot for
 * the AI blocker — because a note about one thing should look like that thing.
 * `title` is optional: a single sentence does not need a heading over it, and
 * "When to use this" does.
 */
export function Note({ icon: Icon = Info, title, children, className }) {
  return (
    <div
      className={cn(
        "flex items-start gap-2.5 rounded-lg border border-primary/20 bg-primary/5 p-3 text-sm",
        className,
      )}
    >
      <Icon className="mt-0.5 size-4 shrink-0 text-primary" />
      <div className="min-w-0 space-y-1">
        {title ? <p className="font-medium text-foreground">{title}</p> : null}
        {/* A <div>, not a <p>: callers pass sentences, but some pass a list or
            a line with a link in it, and a <p> wrapping those is invalid. */}
        <div className="text-muted-foreground">{children}</div>
      </div>
    </div>
  );
}

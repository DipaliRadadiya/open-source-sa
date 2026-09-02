import { Loader2 } from "lucide-react";
import { cn } from "@/lib/utils";
import { Switch } from "@/components/ui/switch";

/**
 * A switch that says something is happening, and shows the state you asked for
 * while it happens.
 *
 * A plain `<Switch checked={serverValue}>` has two problems the moment the write
 * is not instant, and these writes rewrite a config file and reload a daemon:
 *
 *  - it does not move when you click it, because `checked` is still the old
 *    server value — so the click reads as ignored, and
 *  - a `disabled` switch looks only slightly faded, which is not a way to say
 *    "this is in flight".
 *
 * `pending` puts a spinner beside it and locks it; `checked` should be the value
 * the user asked for, not the one the server has caught up to yet.
 *
 * The spinner sits AFTER the switch, in a slot that is always present. Before,
 * it was rendered conditionally and ahead of the control, so the switch itself
 * jumped sideways the instant you clicked it — the one moment the eye is
 * locked on it. Reserving the space costs 16px of a table cell and nothing
 * moves.
 */
export function PendingSwitch({ pending = false, disabled = false, className, ...props }) {
  return (
    <span className={cn("inline-flex items-center gap-2", className)}>
      <Switch {...props} disabled={disabled || pending} aria-busy={pending} />
      <span className="inline-flex size-4 shrink-0 items-center justify-center">
        {pending ? (
          <Loader2
            className="size-3.5 animate-spin text-muted-foreground"
            aria-hidden
          />
        ) : null}
      </span>
    </span>
  );
}

"use client";

import { Loader2 } from "lucide-react";
import { cn } from "@/lib/utils";
import { Switch } from "@/components/ui/switch";

/**
 * A switch that says something is happening, and shows the state you asked for
 * while it happens.
 *
 * A plain `<Switch checked={serverValue}>` has two problems the moment the write
 * is not instant, and this screen's write rewrites a config file and reloads a
 * daemon:
 *
 *  - it does not move when you click it, because `checked` is still the old
 *    server value — so the click reads as ignored, and
 *  - a `disabled` switch looks only slightly faded, which is not a way to say
 *    "this is in flight".
 *
 * `pending` puts a spinner beside it and locks it; `checked` should be the value
 * the user asked for, not the one the server has caught up to yet.
 */
export function PendingSwitch({ pending = false, disabled = false, className, ...props }) {
  return (
    <span className={cn("inline-flex items-center gap-2", className)}>
      {pending ? (
        <Loader2 className="size-3.5 shrink-0 animate-spin text-muted-foreground" aria-hidden />
      ) : null}
      <Switch {...props} disabled={disabled || pending} />
    </span>
  );
}

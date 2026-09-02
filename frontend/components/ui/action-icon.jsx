import { Loader2 } from "lucide-react";
import { cn } from "@/lib/utils";

/**
 * A button's icon, which becomes a spinner while the button is working.
 *
 * Fourteen buttons across the panel started an API call and then only went
 * `disabled`. Disabling is not feedback: nothing on screen changes, so the
 * click reads as ignored and people press again. The ones that got this right
 * all did the same thing by hand, which is why the ones that got it wrong were
 * invisible — there was nothing to be inconsistent with.
 *
 * Swaps to `Loader2` rather than spinning the icon in place, because most of
 * these are not circular: a spinning play triangle or plug reads as broken.
 * `RefreshCw` and `RotateCw` are the exception and already spin themselves —
 * those keep their own icon and just take the class.
 */
export function ActionIcon({ icon: Icon, pending = false, className, ...props }) {
  const spinsWell = Icon?.displayName === "RefreshCw" || Icon?.displayName === "RotateCw";
  const Rendered = pending && !spinsWell ? Loader2 : Icon;

  return (
    <Rendered
      className={cn("size-4", pending && "animate-spin", className)}
      aria-hidden
      {...props}
    />
  );
}

import { Loader2 } from "lucide-react";
import { cn } from "@/lib/utils";

// Swaps to `Loader2`: most icons look broken spinning; `RefreshCw`/`RotateCw` spin as-is.
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

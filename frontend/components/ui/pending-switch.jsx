import { Loader2 } from "lucide-react";
import { cn } from "@/lib/utils";
import { Switch } from "@/components/ui/switch";

// `checked` should be the requested value, not the server's. The spinner slot is always
// reserved so nothing shifts; `aside` fills it when not pending.
export function PendingSwitch({ pending = false, disabled = false, aside = null, className, onCheckedChange, ...props }) {
  return (
    <span className={cn("inline-flex items-center gap-2", className)}>
      {/* Locked by ignoring changes, not `disabled`, which would drop keyboard focus. */}
      <Switch
        {...props}
        disabled={disabled}
        aria-busy={pending}
        aria-disabled={pending}
        className={cn(pending && "cursor-progress opacity-50")}
        onCheckedChange={pending ? undefined : onCheckedChange}
      />
      <span className="inline-flex size-4 shrink-0 items-center justify-center">
        {pending ? (
          <Loader2
            className="size-3.5 animate-spin text-muted-foreground"
            aria-hidden
          />
        ) : (
          aside
        )}
      </span>
    </span>
  );
}

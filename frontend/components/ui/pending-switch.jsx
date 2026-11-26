import { Loader2 } from "lucide-react";
import { cn } from "@/lib/utils";
import { Switch } from "@/components/ui/switch";

export function PendingSwitch({ pending = false, disabled = false, aside = null, className, onCheckedChange, readOnly = false, ...props }) {
  if (readOnly) return <Switch {...props} readOnly />;

  return (
    <span className={cn("inline-flex items-center gap-2", className)}>
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

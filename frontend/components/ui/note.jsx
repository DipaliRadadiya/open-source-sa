import { Info } from "lucide-react";
import { cn } from "@/lib/utils";

/**
 * An informational note about a feature: the calm sibling of `Caution`, with
 * the same shape. `icon` is the feature's own mark; `title` is optional.
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
        {/* A <div>, not a <p>: callers may pass lists or block content. */}
        <div className="text-muted-foreground">{children}</div>
      </div>
    </div>
  );
}

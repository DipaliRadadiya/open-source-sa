import { cn } from "@/lib/utils";

// `compact` is for an empty state INSIDE another card: a filled well instead of the dashed border.
export function EmptyState({ icon: Icon, title, description, action, compact = false }) {
  return (
    // px-6 keeps the description off the border on phones.
    <div
      className={cn(
        "flex flex-col items-center justify-center rounded-xl text-center",
        // Filled when compact, dashed when it fills a page.
        compact ? "gap-2 bg-muted/40 px-6 py-8" : "gap-3 border border-dashed px-6 py-16",
      )}
    >
      <span
        className={cn(
          "flex items-center justify-center rounded-full text-muted-foreground",
          // On the muted well the icon disc needs a contrasting background.
          compact ? "size-8 bg-background shadow-e1" : "size-12 bg-muted",
        )}
      >
        <Icon className={compact ? "size-4" : "size-5"} />
      </span>
      {/* Description is optional; omit it when it would only restate the title. */}
      <div className="space-y-1">
        <p className={cn("font-medium", compact && "text-sm")}>{title}</p>
        {description ? (
          <p className="max-w-sm text-sm text-pretty text-muted-foreground">{description}</p>
        ) : null}
      </div>
      {action}
    </div>
  );
}

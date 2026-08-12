import { cn } from "@/lib/utils"

function Skeleton({
  className,
  ...props
}) {
  return (
    <div
      data-slot="skeleton"
      // max-w-full: the placeholder bars are written at the width the real text
      // will be on a desktop (w-96, w-80…), which is wider than a phone. Capped
      // here rather than in fifty loading.jsx files, and a caller can still
      // override it.
      className={cn("max-w-full animate-pulse rounded-md bg-muted", className)}
      {...props} />
  );
}

export { Skeleton }

import { cn } from "@/lib/utils"

function Skeleton({
  className,
  ...props
}) {
  return (
    <div
      data-slot="skeleton"
      // max-w-full: fixed desktop widths (w-96…) must not overflow a phone.
      className={cn("max-w-full animate-pulse rounded-md bg-muted", className)}
      {...props} />
  );
}

export { Skeleton }

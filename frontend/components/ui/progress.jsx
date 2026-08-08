"use client"

import * as React from "react"
import { Progress as ProgressPrimitive } from "radix-ui"

import { cn } from "@/lib/utils"

function Progress({
  className,
  value,
  // For work whose length is genuinely unknown — a request that reports no
  // steps. A determinate bar would have to invent a number, and a bar that
  // creeps to 90% and waits is the oldest lie in progress UI. Radix already
  // models this: `value={null}` is its indeterminate state.
  indeterminate = false,
  ...props
}) {
  return (
    <ProgressPrimitive.Root
      data-slot="progress"
      value={indeterminate ? null : value}
      className={cn(
        "relative flex h-1 w-full items-center overflow-x-hidden rounded-full bg-muted",
        className
      )}
      {...props}>
      <ProgressPrimitive.Indicator
        data-slot="progress-indicator"
        className={cn(
          "size-full flex-1 bg-primary transition-all",
          // A stripe that keeps sweeping: motion says "still working" without
          // claiming to know how far along it is.
          indeterminate && "w-2/5 flex-none animate-progress-sweep rounded-full"
        )}
        style={indeterminate ? undefined : { transform: `translateX(-${100 - (value || 0)}%)` }} />
    </ProgressPrimitive.Root>
  );
}

export { Progress }

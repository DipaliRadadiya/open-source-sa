"use client"

import * as React from "react"
import { Switch as SwitchPrimitive } from "radix-ui"

import { ReasonTooltip, useDisabledReason } from "@/components/ui/reason-tooltip";
import { cn } from "@/lib/utils"

function Switch({
  className,
  size = "default",
  disabled = false,
  disabledReason,
  ...props
}) {
  const inheritedReason = useDisabledReason();
  const control = (
    <SwitchPrimitive.Root
      data-slot="switch"
      data-size={size}
      className={cn(
        "peer group/switch relative inline-flex shrink-0 items-center rounded-full border border-transparent transition-all outline-none after:absolute after:-inset-x-3 after:-inset-y-2 focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-3 aria-invalid:ring-destructive/20 data-[size=default]:h-[18.4px] data-[size=default]:w-[32px] data-[size=sm]:h-[14px] data-[size=sm]:w-[24px] data-checked:bg-primary data-unchecked:bg-input dark:data-unchecked:bg-input/80 data-disabled:cursor-not-allowed data-disabled:opacity-50",
        className
      )}
      disabled={disabled}
      {...props}>
      <SwitchPrimitive.Thumb
        data-slot="switch-thumb"
        className="pointer-events-none block rounded-full bg-background ring-0 transition-transform group-data-[size=default]/switch:size-4 group-data-[size=sm]/switch:size-3 group-data-[size=default]/switch:data-checked:translate-x-[calc(100%-2px)] group-data-[size=sm]/switch:data-checked:translate-x-[calc(100%-2px)] dark:data-checked:bg-primary-foreground group-data-[size=default]/switch:data-unchecked:translate-x-0 group-data-[size=sm]/switch:data-unchecked:translate-x-0 dark:data-unchecked:bg-foreground" />
    </SwitchPrimitive.Root>
  );

  // A parent already showing a tooltip over this area wins — two bubbles for
  // one control is worse than none. A parent that only SUPPLIES a reason does
  // not, so the control renders it as its own.
  if (disabled && inheritedReason?.handled && !disabledReason) return control;

  return (
    <ReasonTooltip reason={
        disabled
          ? (disabledReason ?? inheritedReason?.reason)
          : null
      }>
      {control}
    </ReasonTooltip>
  );
}

export { Switch }

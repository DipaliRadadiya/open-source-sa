"use client"

import * as React from "react"
import { cva } from "class-variance-authority";
import { Slot } from "radix-ui"

import { DENIED, ReasonTooltip, useDisabledReason } from "@/components/ui/reason-tooltip";
import { cn } from "@/lib/utils"

const ON_NOTE =
  "in-data-[slot=caution]:border-border! in-data-[slot=caution]:bg-card! in-data-[slot=caution]:text-foreground! in-data-[slot=caution]:shadow-xs in-data-[slot=caution]:hover:bg-muted! in-data-[slot=caution]:aria-expanded:bg-muted! in-data-[slot=caution]:dark:border-input! in-data-[slot=caution]:dark:bg-input/40! in-data-[slot=caution]:dark:hover:bg-input/60! in-data-[slot=notice]:border-border! in-data-[slot=notice]:bg-card! in-data-[slot=notice]:text-foreground! in-data-[slot=notice]:shadow-xs in-data-[slot=notice]:hover:bg-muted! in-data-[slot=notice]:aria-expanded:bg-muted! in-data-[slot=notice]:dark:border-input! in-data-[slot=notice]:dark:bg-input/40! in-data-[slot=notice]:dark:hover:bg-input/60!";

const NEUTRAL =
  "border-border bg-secondary hover:bg-[color-mix(in_oklch,var(--secondary),var(--foreground)_6%)] hover:text-foreground aria-expanded:bg-[color-mix(in_oklch,var(--secondary),var(--foreground)_6%)] aria-expanded:text-foreground dark:border-input dark:bg-input/40 dark:hover:bg-input/60";

const buttonVariants = cva(
  "group/button inline-flex shrink-0 items-center justify-center rounded-lg border border-transparent bg-clip-padding text-sm font-medium whitespace-nowrap transition-all outline-none select-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 active:not-aria-[haspopup]:translate-y-px disabled:pointer-events-none disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-3 aria-invalid:ring-destructive/20 dark:aria-invalid:border-destructive/50 dark:aria-invalid:ring-destructive/40 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4",
  {
    variants: {
      variant: {
        default:
          "bg-primary text-primary-foreground shadow-xs hover:bg-[color-mix(in_oklch,var(--primary),black_8%)] hover:shadow-sm dark:hover:bg-[color-mix(in_oklch,var(--primary),white_10%)]",
        outline: "",
        neutral: NEUTRAL,
        field:
          "border-input bg-transparent hover:bg-muted/40 aria-expanded:bg-transparent dark:bg-input/30 dark:hover:bg-input/50",
        secondary:
          "bg-secondary text-secondary-foreground hover:bg-[color-mix(in_oklch,var(--secondary),var(--foreground)_5%)] aria-expanded:bg-secondary aria-expanded:text-secondary-foreground",
        ghost:
          "hover:bg-muted hover:text-foreground aria-expanded:bg-muted aria-expanded:text-foreground dark:hover:bg-muted/50",
        destructive:
          "bg-[color-mix(in_oklch,var(--destructive)_10%,var(--background))] text-destructive hover:bg-[color-mix(in_oklch,var(--destructive)_18%,var(--background))] focus-visible:border-destructive/40 focus-visible:ring-destructive/20 dark:bg-destructive/20 dark:text-[color-mix(in_oklch,var(--destructive)_80%,var(--foreground))] dark:hover:bg-destructive/30 dark:focus-visible:ring-destructive/40",
        link: "text-primary underline-offset-4 hover:underline",
      },
      size: {
        default:
          "h-9 gap-1.5 px-3.5 has-data-[icon=inline-end]:pr-2.5 has-data-[icon=inline-start]:pl-2.5",
        xs: "h-6 gap-1 rounded-[min(var(--radius-md),10px)] px-2 text-xs in-data-[slot=button-group]:rounded-lg has-data-[icon=inline-end]:pr-1.5 has-data-[icon=inline-start]:pl-1.5 [&_svg:not([class*='size-'])]:size-3",
        sm: "h-8 gap-1.5 rounded-[min(var(--radius-md),12px)] px-3 text-sm in-data-[slot=button-group]:rounded-lg has-data-[icon=inline-end]:pr-2 has-data-[icon=inline-start]:pl-2 [&_svg:not([class*='size-'])]:size-3.5",
        lg: "h-9 gap-1.5 px-2.5 has-data-[icon=inline-end]:pr-2 has-data-[icon=inline-start]:pl-2",
        icon: "size-8",
        "icon-xs":
          "size-6 rounded-[min(var(--radius-md),10px)] in-data-[slot=button-group]:rounded-lg [&_svg:not([class*='size-'])]:size-3",
        "icon-sm":
          "size-8 rounded-[min(var(--radius-md),12px)] in-data-[slot=button-group]:rounded-lg",
        "icon-lg": "size-9",
      },
    },
    compoundVariants: [
      { variant: "outline", size: ["default", "xs", "sm", "lg"], className: `${NEUTRAL} ${ON_NOTE}` },
      { variant: "outline", size: ["icon", "icon-xs", "icon-sm", "icon-lg"], className: NEUTRAL },
    ],
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  }
)

function Button({
  className,
  variant = "default",
  size = "default",
  asChild = false,
  disabled = false,
  disabledReason,
  denied = false,
  ...props
}) {
  const inheritedReason = useDisabledReason();
  if (denied || disabledReason === DENIED || (disabled && inheritedReason?.denied)) return null;
  const Comp = asChild ? Slot.Root : "button";
  // Pressing Save would blur the field first; its blur validation shows or hides an error,
  // the layout shifts, and the click lands beside the moved button. Keep focus where it is.
  const onMouseDown =
    !asChild && props.type === "submit"
      ? (event) => {
          props.onMouseDown?.(event);
          event.preventDefault();
        }
      : props.onMouseDown;
  const control = (
    <Comp
      data-slot="button"
      data-variant={variant}
      data-size={size}
      className={cn(buttonVariants({ variant, size, className }))}
      disabled={disabled}
      {...props}
      onMouseDown={onMouseDown}
    />
  );

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

export { Button, buttonVariants }

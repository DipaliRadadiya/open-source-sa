"use client"

import * as React from "react"
import { cva } from "class-variance-authority";
import { Slot } from "radix-ui"

import { ReasonTooltip, useDisabledReason } from "@/components/ui/reason-tooltip";
import { cn } from "@/lib/utils"

// Brand tint for labelled outline buttons in a card, Caution or notice; dialogs, toolbars
// and the header stay neutral. Written out per scope: Tailwind needs literal classes.
const TINT_IN_CARDS =
  "in-[.bg-card]:border-transparent in-[.bg-card]:bg-[color-mix(in_oklch,var(--primary)_9%,var(--background))] in-[.bg-card]:text-[color-mix(in_oklch,var(--primary)_80%,var(--foreground))] in-[.bg-card]:hover:bg-[color-mix(in_oklch,var(--primary)_16%,var(--background))] in-[.bg-card]:aria-expanded:bg-[color-mix(in_oklch,var(--primary)_16%,var(--background))] in-[.bg-card]:dark:bg-primary/15 in-[.bg-card]:dark:text-[color-mix(in_oklch,var(--primary)_70%,white)] in-[.bg-card]:dark:hover:bg-primary/25 in-[.bg-card]:dark:aria-expanded:bg-primary/25 in-data-[slot=caution]:border-transparent in-data-[slot=caution]:bg-[color-mix(in_oklch,var(--primary)_9%,var(--background))] in-data-[slot=caution]:text-[color-mix(in_oklch,var(--primary)_80%,var(--foreground))] in-data-[slot=caution]:hover:bg-[color-mix(in_oklch,var(--primary)_16%,var(--background))] in-data-[slot=caution]:aria-expanded:bg-[color-mix(in_oklch,var(--primary)_16%,var(--background))] in-data-[slot=caution]:dark:bg-primary/15 in-data-[slot=caution]:dark:text-[color-mix(in_oklch,var(--primary)_70%,white)] in-data-[slot=caution]:dark:hover:bg-primary/25 in-data-[slot=caution]:dark:aria-expanded:bg-primary/25 in-data-[slot=notice]:border-transparent in-data-[slot=notice]:bg-[color-mix(in_oklch,var(--primary)_9%,var(--background))] in-data-[slot=notice]:text-[color-mix(in_oklch,var(--primary)_80%,var(--foreground))] in-data-[slot=notice]:hover:bg-[color-mix(in_oklch,var(--primary)_16%,var(--background))] in-data-[slot=notice]:aria-expanded:bg-[color-mix(in_oklch,var(--primary)_16%,var(--background))] in-data-[slot=notice]:dark:bg-primary/15 in-data-[slot=notice]:dark:text-[color-mix(in_oklch,var(--primary)_70%,white)] in-data-[slot=notice]:dark:hover:bg-primary/25 in-data-[slot=notice]:dark:aria-expanded:bg-primary/25";

// Icon-only buttons stay neutral everywhere; tinted icon squares read as noise.
const NEUTRAL =
  "border-border bg-secondary hover:bg-[color-mix(in_oklch,var(--secondary),var(--foreground)_6%)] hover:text-foreground aria-expanded:bg-[color-mix(in_oklch,var(--secondary),var(--foreground)_6%)] aria-expanded:text-foreground dark:border-input dark:bg-input/40 dark:hover:bg-input/60";

const buttonVariants = cva(
  "group/button inline-flex shrink-0 items-center justify-center rounded-lg border border-transparent bg-clip-padding text-sm font-medium whitespace-nowrap transition-all outline-none select-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 active:not-aria-[haspopup]:translate-y-px disabled:pointer-events-none disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-3 aria-invalid:ring-destructive/20 dark:aria-invalid:border-destructive/50 dark:aria-invalid:ring-destructive/40 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4",
  {
    variants: {
      variant: {
        default:
          "bg-primary text-primary-foreground shadow-xs hover:bg-primary/90 hover:shadow-sm",
        // Filled plus border so it reads on white and `bg-muted`; colour comes from
        // compoundVariants below.
        outline: "",
        // The same neutral look for header chrome that has a label (the
        // language switcher), which sits beside icon-only controls.
        neutral: NEUTRAL,
        // A button that stands in for an input (the combobox trigger): it has
        // to look like the Select and Input beside it, not like an action.
        field:
          "border-input bg-transparent hover:bg-muted/40 aria-expanded:bg-transparent dark:bg-input/30 dark:hover:bg-input/50",
        secondary:
          "bg-secondary text-secondary-foreground hover:bg-[color-mix(in_oklch,var(--secondary),var(--foreground)_5%)] aria-expanded:bg-secondary aria-expanded:text-secondary-foreground",
        ghost:
          "hover:bg-muted hover:text-foreground aria-expanded:bg-muted aria-expanded:text-foreground dark:hover:bg-muted/50",
        // Solid tint, not /10: a translucent one takes on the card colour and loses contrast.
        destructive:
          "bg-[color-mix(in_oklch,var(--destructive)_10%,var(--background))] text-destructive hover:bg-[color-mix(in_oklch,var(--destructive)_18%,var(--background))] focus-visible:border-destructive/40 focus-visible:ring-destructive/20 dark:bg-destructive/20 dark:text-[color-mix(in_oklch,var(--destructive)_80%,var(--foreground))] dark:hover:bg-destructive/30 dark:focus-visible:ring-destructive/40",
        link: "text-primary underline-offset-4 hover:underline",
      },
      size: {
        default:
          "h-9 gap-1.5 px-3.5 has-data-[icon=inline-end]:pr-2.5 has-data-[icon=inline-start]:pl-2.5",
        xs: "h-6 gap-1 rounded-[min(var(--radius-md),10px)] px-2 text-xs in-data-[slot=button-group]:rounded-lg has-data-[icon=inline-end]:pr-1.5 has-data-[icon=inline-start]:pl-1.5 [&_svg:not([class*='size-'])]:size-3",
        // h-8 with a full-size label (shadcn's `sm`), so row actions read as buttons.
        sm: "h-8 gap-1.5 rounded-[min(var(--radius-md),12px)] px-3 text-sm in-data-[slot=button-group]:rounded-lg has-data-[icon=inline-end]:pr-2 has-data-[icon=inline-start]:pl-2 [&_svg:not([class*='size-'])]:size-3.5",
        lg: "h-9 gap-1.5 px-2.5 has-data-[icon=inline-end]:pr-2 has-data-[icon=inline-start]:pl-2",
        icon: "size-8",
        "icon-xs":
          "size-6 rounded-[min(var(--radius-md),10px)] in-data-[slot=button-group]:rounded-lg [&_svg:not([class*='size-'])]:size-3",
        // Square with `sm`, so an icon button and a labelled one in the same
        // row action cluster keep the same height.
        "icon-sm":
          "size-8 rounded-[min(var(--radius-md),12px)] in-data-[slot=button-group]:rounded-lg",
        "icon-lg": "size-9",
      },
    },
    compoundVariants: [
      { variant: "outline", size: ["default", "xs", "sm", "lg"], className: `${NEUTRAL} ${TINT_IN_CARDS}` },
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
  ...props
}) {
  const inheritedReason = useDisabledReason();
  const Comp = asChild ? Slot.Root : "button";
  const control = (
    <Comp
      data-slot="button"
      data-variant={variant}
      data-size={size}
      className={cn(buttonVariants({ variant, size, className }))}
      disabled={disabled}
      {...props}
    />
  );

  // Disabled buttons get a reason tooltip (own `disabledReason` or inherited).
  // A parent already showing a tooltip here wins, to avoid two bubbles.
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

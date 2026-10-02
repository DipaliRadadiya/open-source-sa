"use client"

import * as React from "react"
import { cva } from "class-variance-authority";
import { Slot } from "radix-ui"

import { cn } from "@/lib/utils"

// Tinted pills (`success`/`warning`/`destructive`/`muted`) mean a state; the rest are quiet labels.
const badgeVariants = cva(
  "group/badge inline-flex h-5 w-fit shrink-0 items-center justify-center gap-1 overflow-hidden rounded-4xl border border-transparent px-2 py-0.5 text-xs font-medium whitespace-nowrap transition-all focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 has-data-[icon=inline-end]:pr-1.5 has-data-[icon=inline-start]:pl-1.5 aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 [&>svg]:pointer-events-none [&>svg]:size-3!",
  {
    variants: {
      variant: {
        // Not a status: a label. Reads as text, keeps its meaning.
        // Text mixed toward foreground like Button's tinted variant: plain primary on this tint is 4.38:1.
        default: "rounded-md bg-primary/10 px-1.5 text-[color-mix(in_oklch,var(--primary)_80%,var(--foreground))] dark:text-primary [a]:hover:bg-primary/15",
        secondary: "rounded-md px-1 text-muted-foreground [a]:hover:text-foreground",
        destructive:
          "bg-destructive/10 text-destructive focus-visible:ring-destructive/20 dark:bg-destructive/20 dark:focus-visible:ring-destructive/40 [a]:hover:bg-destructive/20",
        // Text mixed towards the foreground for contrast on tinted cards.
        success:
          "bg-success/10 text-[color-mix(in_oklch,var(--success)_80%,var(--foreground))] dark:bg-success/20 dark:text-[color-mix(in_oklch,var(--success)_85%,var(--foreground))]",
        warning:
          "bg-warning/15 text-[color-mix(in_oklch,var(--warning)_75%,var(--foreground))] dark:bg-warning/20 dark:text-warning",
        // A neutral status pill (e.g. Pending); `secondary` is a label, not a state.
        muted:
          "bg-muted text-muted-foreground dark:bg-muted/60",
        outline:
          "rounded-md border-border/70 px-1.5 text-muted-foreground [a]:hover:text-foreground",
        ghost:
          "hover:bg-muted hover:text-muted-foreground dark:hover:bg-muted/50",
        link: "text-primary underline-offset-4 hover:underline",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  }
)

function Badge({
  className,
  variant = "default",
  asChild = false,
  ...props
}) {
  const Comp = asChild ? Slot.Root : "span"

  return (
    <Comp
      data-slot="badge"
      data-variant={variant}
      className={cn(badgeVariants({ variant }), className)}
      {...props} />
  );
}

export { Badge, badgeVariants }

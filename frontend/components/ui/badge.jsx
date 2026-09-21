"use client"

import * as React from "react"
import { cva } from "class-variance-authority";
import { Slot } from "radix-ui"

import { cn } from "@/lib/utils"

/*
 * A pill is a claim that something has a STATE. Of 167 badges in the panel,
 * only 57 carried one — the other 110 were labels wearing a status costume, so
 * the real ones (a stopped service, an expiring certificate) had to compete
 * with a row of decorative capsules for the same attention.
 *
 * `success` / `warning` / `destructive` keep the tinted pill. `outline`,
 * `secondary` and `default` become quiet: no fill, no capsule, just a typed
 * label. Nothing is hidden and no call site changes — they simply stop
 * shouting.
 */
const badgeVariants = cva(
  "group/badge inline-flex h-5 w-fit shrink-0 items-center justify-center gap-1 overflow-hidden rounded-4xl border border-transparent px-2 py-0.5 text-xs font-medium whitespace-nowrap transition-all focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 has-data-[icon=inline-end]:pr-1.5 has-data-[icon=inline-start]:pl-1.5 aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 [&>svg]:pointer-events-none [&>svg]:size-3!",
  {
    variants: {
      variant: {
        // Not a status: a label. Reads as text, keeps its meaning.
        default: "rounded-md bg-primary/10 px-1.5 text-primary [a]:hover:bg-primary/15",
        secondary: "rounded-md px-1 text-muted-foreground [a]:hover:text-foreground",
        destructive:
          "bg-destructive/10 text-destructive focus-visible:ring-destructive/20 dark:bg-destructive/20 dark:focus-visible:ring-destructive/40 [a]:hover:bg-destructive/20",
        success:
          "bg-success/10 text-success dark:bg-success/20",
        warning:
          "bg-warning/15 text-warning dark:bg-warning/20",
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

import * as React from "react"

import { cn } from "@/lib/utils"

/*
 * One edge treatment: a hairline border, no ring or shadow. Elevation is for
 * floating surfaces (popover, dropdown, dialog). Type scale: title 16 semibold,
 * body 14, description 12.
 */

function Card({
  className,
  size = "default",
  ...props
}) {
  return (
    <div
      data-slot="card"
      data-size={size}
      className={cn(
        "group/card flex flex-col gap-(--card-spacing) overflow-hidden rounded-lg border border-border bg-card py-(--card-spacing) text-sm text-card-foreground [--card-spacing:--spacing(4)] has-data-[slot=card-footer]:pb-0 has-[>img:first-child]:pt-0 data-[size=sm]:[--card-spacing:--spacing(3)] data-[size=sm]:has-data-[slot=card-footer]:pb-0 *:[img:first-child]:rounded-t-lg *:[img:last-child]:rounded-b-lg",
        className
      )}
      {...props} />
  );
}

function CardHeader({
  className,
  ...props
}) {
  return (
    <div
      data-slot="card-header"
      className={cn(
        "group/card-header @container/card-header grid auto-rows-min items-start gap-0.5 rounded-t-lg px-(--card-spacing) has-data-[slot=card-action]:grid-cols-[1fr_auto] has-data-[slot=card-description]:grid-rows-[auto_auto] [.border-b]:pb-(--card-spacing)",
        className
      )}
      {...props} />
  );
}

/*
 * A <div> by default, since cards also nest inside dialogs and sections.
 * Top-level page sections pass `as="h2"` to give the page a heading outline.
 */
function CardTitle({
  as: Comp = "div",
  className,
  ...props
}) {
  return (
    <Comp
      data-slot="card-title"
      className={cn(
        "font-heading text-base leading-snug font-semibold tracking-tight group-data-[size=sm]/card:text-sm",
        className
      )}
      {...props} />
  );
}

function CardDescription({
  className,
  ...props
}) {
  return (
    <div
      data-slot="card-description"
      className={cn("text-xs leading-relaxed text-muted-foreground", className)}
      {...props} />
  );
}

function CardAction({
  className,
  ...props
}) {
  return (
    <div
      data-slot="card-action"
      className={cn(
        "col-start-2 row-span-2 row-start-1 self-start justify-self-end",
        className
      )}
      {...props} />
  );
}

function CardContent({
  className,
  ...props
}) {
  return (
    <div
      data-slot="card-content"
      className={cn("px-(--card-spacing)", className)}
      {...props} />
  );
}

function CardFooter({
  className,
  ...props
}) {
  return (
    <div
      data-slot="card-footer"
      className={cn(
        "flex items-center rounded-b-lg border-t bg-muted/40 p-(--card-spacing)",
        className
      )}
      {...props} />
  );
}

export {
  Card,
  CardHeader,
  CardFooter,
  CardTitle,
  CardAction,
  CardDescription,
  CardContent,
}

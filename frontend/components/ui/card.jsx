import * as React from "react"

import { cn } from "@/lib/utils"

/*
 * ONE edge treatment, not three.
 *
 * Krishna: make it read like "a professional server-management product, not an
 * AI-generated SaaS template". The card drew its boundary three ways at once —
 * `ring-1 ring-foreground/10` AND `shadow-sm` AND a 14px radius — and with 370
 * of them in the panel (11 on the dashboard alone) that stacked into 29
 * bordered and 12 shadowed elements on a single screen. A hairline border is
 * enough for something sitting IN the page; elevation is reserved for things
 * that float over it, which is why `popover`, `dropdown-menu` and `dialog`
 * keep their shadow and are untouched here.
 *
 * The type scale is the other half. 97% of the text on the dashboard measured
 * 14px, so nothing looked more important than anything else and the boxes were
 * doing the work that type should do. A card now reads 16 semibold / 14 / 12
 * instead of 16 medium / 14 / 14.
 *
 * Deliberately NOT tighter. "dont make anything too compact. ui should be
 * breathable and properly scannable" — the padding is unchanged; the density
 * comes from removing chrome, not from squeezing content.
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
 * `as` exists because a card title usually IS a section heading, and shipping
 * it as a <div> left whole pages with a single <h1> and no outline beneath it —
 * nothing to jump between with a screen reader, and no structure for anything
 * that reads the document rather than looks at it.
 *
 * It stays a <div> by default: cards also appear inside dialogs and nested in
 * other sections, where an <h2> would land at the wrong depth. Callers that
 * know they are a top-level section on a page pass `as="h2"`.
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

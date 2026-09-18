"use client";

import Link from "next/link";
import { useTranslations } from "next-intl";
import { CircleAlert, ChevronDown, ChevronRight } from "lucide-react";
import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { useHoverPopover } from "@/lib/hooks/use-hover-popover";

/**
 * Site health as a chip on a line the card already has, with the detail one
 * click away.
 *
 * A chip reading "1 site needs attention" answers none of the three questions
 * it raises — which site, what is wrong, what do I do — so the chip is only
 * the door. Everything behind it names the site, says the problem in a
 * sentence a non-sysadmin can act on, and links to the screen that fixes it
 * rather than to the site's front page.
 *
 * A Popover, not a Tooltip: Radix tooltips never open on touch, so on a phone
 * a tooltip here would be a dead end.
 *
 * Silent when nothing is wrong. The services chip beside it says "All 7
 * running" even when fine because that is a fact somebody may want confirmed;
 * a second permanent reassurance would just crowd the busiest line on the card.
 */
export function SiteAttention({ findings = [] }) {
  const t = useTranslations("serverDashboard.attention");
  /*
   * Hover opens it as well as click.
   *
   * The chip sits in a row of purely decorative badges — php 8.4, node 24,
   * "All 9 services running" — so nothing about being a badge suggested this
   * one did anything. Hover is half the answer; the chevron below is the other
   * half, and the one that works for a finger or a keyboard.
   *
   * 180ms rather than instant: this panel is ~350px of card, and a pointer
   * crossing the footer on its way elsewhere should not fire it.
   */
  const { open, onOpenChange, hoverOpened, triggerProps, contentProps } = useHoverPopover({
    openDelay: 180,
  });

  if (!findings.length) return null;

  // One finding names itself on the chip — that is the whole answer, and
  // making somebody open a popover to read six words is a click charged for
  // nothing.
  const label =
    findings.length === 1
      ? t(`${findings[0].kind}.chip`, { site: findings[0].site })
      : t("count", { count: findings.length });

  return (
    <Popover open={open} onOpenChange={onOpenChange}>
      <PopoverTrigger asChild>
        <Badge
          asChild
          variant="warning"
          className="cursor-pointer gap-1.5 py-1 font-medium transition-colors hover:bg-warning/20 aria-expanded:bg-warning/20"
        >
          <button type="button" {...triggerProps}>
            <CircleAlert className="size-3.5 shrink-0" />
            {label}
            {/*
             * The affordance. A chevron is the one mark that reads as "there is
             * more behind this" without a pointer, a hover, or a language —
             * and it doubles as the state, so an open panel is not a mystery
             * about which chip it belongs to.
             */}
            <ChevronDown
              className={cn(
                "size-3.5 shrink-0 opacity-70 transition-transform",
                open && "rotate-180",
              )}
              aria-hidden
            />
          </button>
        </Badge>
      </PopoverTrigger>

      <PopoverContent
        align="end"
        className="w-[22rem] p-0"
        {...contentProps}
        // A panel the pointer merely summoned must not take focus off whatever
        // the reader was doing. One opened by a click or the keyboard must hand
        // focus over, or the Fix button inside is unreachable without a mouse.
        onOpenAutoFocus={(event) => {
          if (hoverOpened.current) event.preventDefault();
        }}
      >
        <p className="border-b px-4 py-2.5 text-sm font-medium">{t("title")}</p>
        {/* Bounded and scrolled. Four findings already reach 550px; a server
            with twenty broken sites would otherwise open a popover taller than
            the window, with its last rows unreachable. */}
        <ul className="max-h-[21rem] divide-y overflow-y-auto">
          {findings.map((finding) => (
            <li key={finding.id} className="space-y-2 px-4 py-3">
              <div className="space-y-1">
                <p className="flex items-center gap-1.5 text-sm font-medium">
                  <CircleAlert className="size-3.5 shrink-0 text-warning" aria-hidden />
                  {finding.site}
                </p>
                {/* Plain language, then the API's own reason when it has one —
                    it knows which step failed and this component does not. */}
                <p className="text-xs leading-5 text-muted-foreground">
                  {t(`${finding.kind}.detail`)}
                  {finding.detail ? ` — ${finding.detail}` : ""}
                </p>
              </div>
              <Button asChild size="sm" variant="outline" className="h-7 w-full justify-between">
                <Link href={finding.href} prefetch={false}>
                  {t(`${finding.kind}.action`)}
                  <ChevronRight className="size-3.5" />
                </Link>
              </Button>
            </li>
          ))}
        </ul>
      </PopoverContent>
    </Popover>
  );
}

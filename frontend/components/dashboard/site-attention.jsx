"use client";

import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { CircleAlert, ChevronDown, ChevronRight } from "lucide-react";
import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { useHoverPopover } from "@/lib/hooks/use-hover-popover";

/**
 * Site health as a chip; the popover names each site, the problem, and links
 * to the screen that fixes it. A Popover, not a Tooltip: Radix tooltips never
 * open on touch. Renders nothing when all is well.
 */
export function SiteAttention({ findings = [] }) {
  const t = useTranslations("serverDashboard.attention");
  // Opens on hover as well as click; the chevron is the cue for touch and
  // keyboard. 180ms delay so a pointer passing by does not open it.
  const { open, onOpenChange, hoverOpened, triggerProps, contentProps } = useHoverPopover({
    openDelay: 180,
  });

  if (!findings.length) return null;

  // A single finding names itself on the chip.
  const label =
    findings.length === 1
      ? t(`${findings[0].kind}.chip`, { site: findings[0].site })
      : // Applications, not findings: the sentence counts applications, and one
        // with two findings must count once.
        t("count", { count: new Set(findings.map((finding) => finding.site)).size });

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
            {/* The chevron signals "more behind this" and shows open state. */}
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
        // Hover-opened: don't steal focus. Click/keyboard-opened: take focus so
        // the Fix button is reachable without a mouse.
        onOpenAutoFocus={(event) => {
          if (hoverOpened.current) event.preventDefault();
        }}
      >
        <p className="border-b px-4 py-2.5 text-sm font-medium">{t("title")}</p>
        {/* Bounded and scrolled so many findings cannot exceed the window. */}
        <ul className="max-h-[21rem] divide-y overflow-y-auto">
          {findings.map((finding) => (
            <li key={finding.id} className="space-y-2 px-4 py-3">
              <div className="space-y-1">
                <p className="flex items-center gap-1.5 text-sm font-medium">
                  <CircleAlert className="size-3.5 shrink-0 text-warning" aria-hidden />
                  {finding.site}
                </p>
                {/* Plain language, then the API's own reason when it has one. */}
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

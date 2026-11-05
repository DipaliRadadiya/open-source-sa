import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { History, MoreHorizontal, Settings2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { HoverHint } from "@/components/ui/hover-hint";
import { CardFactGrid, CardFactStacked, CardListItem } from "@/components/data-table/card-list";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";

/*
 * The phone and tablet layout of the Backups lists, shared by applications and databases
 * (Krishna, 5 Nov: the cards looked "unstructured and messy and uneven"). Every protected
 * card has the same three bands — who, four facts, one action row — so neighbours in the
 * two-column grid end level; anything not set up is a thin row in one list instead.
 */

export function CoverageCard({ title, subtitle, badge, children, actions }) {
  return (
    <CardListItem className="gap-0 p-0">
      <div className="min-w-0 space-y-1 px-4 pt-4 pb-3">
        {title}
        {/* Whole, on its own line: beside a badge the domain was cut in the middle. */}
        {subtitle ? <p className="text-xs break-all text-muted-foreground">{subtitle}</p> : null}
        {badge ? <div className="pt-1">{badge}</div> : null}
      </div>
      <CardFactGrid className="mx-4 grid-cols-2 gap-x-4 gap-y-3 pb-4">{children}</CardFactGrid>
      <div className="mt-auto flex items-center justify-end gap-1 border-t bg-muted/30 px-4 py-2.5">{actions}</div>
    </CardListItem>
  );
}

/** A fact whose label and value rows line up with its neighbour's, even when one label wraps. */
export function CoverageFact({ label, className, children }) {
  return (
    <CardFactStacked label={label} className={["row-span-2 grid grid-rows-subgrid gap-y-1 space-y-0", className].filter(Boolean).join(" ")}>
      {children}
    </CardFactStacked>
  );
}

/** The table's row actions: one button plus a menu for the occasional ones. */
export function CoverageCardActions({ run, historyHref, settingsHref }) {
  const t = useTranslations("backups.coverage");
  return (
    <>
      {run}
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="ghost" size="icon" className="size-8" aria-label={t("moreActions")}>
            <MoreHorizontal className="size-4" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          <DropdownMenuItem asChild>
            <Link href={historyHref} prefetch={false}>
              <History className="size-4" />
              {t("viewBackups")}
            </Link>
          </DropdownMenuItem>
          {settingsHref ? (
            <DropdownMenuItem asChild>
              <Link href={settingsHref} prefetch={false}>
                <Settings2 className="size-4" />
                {t("manage")}
              </Link>
            </DropdownMenuItem>
          ) : null}
        </DropdownMenuContent>
      </DropdownMenu>
    </>
  );
}

/** Everything not set up, as thin rows in one card: eleven identical cards said one sentence eleven times. */
export function NotSetUpList({ title, description, children }) {
  return (
    <section className="overflow-hidden rounded-xl border bg-card shadow-sm">
      <div className="border-b bg-muted/30 px-4 py-3">
        <h3 className="text-sm font-semibold">{title}</h3>
        <p className="mt-0.5 text-xs text-muted-foreground">{description}</p>
      </div>
      <ul className="divide-y">{children}</ul>
    </section>
  );
}

export function NotSetUpRow({ name, href, subtitle, action }) {
  return (
    <li className="flex items-center gap-3 px-4 py-2.5">
      <div className="min-w-0 flex-1">
        <HoverHint content={name}>
          {href ? (
            <Link href={href} prefetch={false} className="block truncate text-sm font-medium underline-offset-4 hover:underline">
              {name}
            </Link>
          ) : (
            <p className="truncate text-sm font-medium">{name}</p>
          )}
        </HoverHint>
        {subtitle ? <p className="text-xs break-all text-muted-foreground">{subtitle}</p> : null}
      </div>
      <div className="shrink-0">{action}</div>
    </li>
  );
}


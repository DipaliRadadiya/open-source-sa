"use client";

import Link from "next/link";
import { useTranslations } from "next-intl";
import { ScrollText } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";

/**
 * Jump to this service's log. The whole point of a failed row is finding out
 * why, and until now that meant leaving for the Logs page and guessing which
 * file belonged to the thing that broke.
 *
 * One source → a plain link. Several (nginx has error and access) → a menu,
 * because picking the wrong one wastes the trip.
 *
 * Nothing renders when `log_keys` is empty: the API only lists sources that
 * exist on the box, so an empty array means there is genuinely nothing to open.
 */
export function ServiceLogsLink({ service }) {
  const t = useTranslations("services");
  const keys = service.log_keys ?? [];

  if (keys.length === 0) return null;

  if (keys.length === 1) {
    return (
      <Tooltip>
        <TooltipTrigger asChild>
          <Button variant="ghost" size="icon" className="size-8" asChild>
            <Link href={`/logs?source=${encodeURIComponent(keys[0])}`} aria-label={t("viewLogs")}>
              <ScrollText className="size-4" />
            </Link>
          </Button>
        </TooltipTrigger>
        <TooltipContent>{t("viewLogs")}</TooltipContent>
      </Tooltip>
    );
  }

  return (
    <DropdownMenu>
      {/* The trigger needs the same tooltip as the single-log variant. Without
          it the icon explained itself on every row EXCEPT the ones with more
          than one log — the rows where it's least obvious what it will do. */}
      <Tooltip>
        <TooltipTrigger asChild>
          <DropdownMenuTrigger asChild>
            <Button variant="ghost" size="icon" className="size-8" aria-label={t("viewLogs")}>
              <ScrollText className="size-4" />
            </Button>
          </DropdownMenuTrigger>
        </TooltipTrigger>
        <TooltipContent>{t("viewLogs")}</TooltipContent>
      </Tooltip>
      <DropdownMenuContent align="end">
        {keys.map((key) => (
          <DropdownMenuItem key={key} asChild>
            <Link href={`/logs?source=${encodeURIComponent(key)}`}>
              <ScrollText className="size-4" />
              {/* The raw key: the Logs page owns the friendly labels, and
                  inventing a second name for the same file here would let the
                  two pages drift apart. */}
              <span className="font-mono text-xs">{key}</span>
            </Link>
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

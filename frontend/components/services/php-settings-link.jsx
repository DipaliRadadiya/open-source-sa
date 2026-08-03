"use client";

import Link from "next/link";
import { useTranslations } from "next-intl";
import { SlidersHorizontal } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";

/**
 * Points at the PHP page from the FPM row.
 *
 * The ini editor used to live here. Everything about a PHP version — the
 * version itself, its extensions and its ini — is on one page now, but people
 * who learned to look on Services shouldn't hit a dead end.
 */
export function PhpSettingsLink({ version }) {
  const t = useTranslations("services");

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <Button variant="ghost" size="sm" asChild aria-label={t("phpSettings")}>
          <Link href={`/php?version=${encodeURIComponent(version)}`}>
            <SlidersHorizontal className="size-4" />
          </Link>
        </Button>
      </TooltipTrigger>
      <TooltipContent>{t("phpSettings")}</TooltipContent>
    </Tooltip>
  );
}

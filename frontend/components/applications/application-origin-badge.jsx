"use client";

import { useTranslations } from "next-intl";
import { Box, FileCode2, PackageCheck } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";

export function ApplicationOriginBadge({ origin, compact = false }) {
  const t = useTranslations("applications");
  let Icon;
  let label;
  let tooltip;

  if (origin?.kind === "recipe") {
    Icon = PackageCheck;
    label = t("origin.recipe");
    tooltip = origin.recipe
      ? t("origin.recipeTooltip", { title: origin.recipe.title, version: origin.recipe.version })
      : label;
  } else if (origin?.kind === "image") {
    Icon = Box;
    label = t("origin.image");
    tooltip = t("origin.imageTooltip", { image: origin.image ?? "—" });
  } else if (origin?.kind === "compose") {
    Icon = FileCode2;
    label = t("origin.compose");
    tooltip = t("origin.composeTooltip");
  } else {
    return null;
  }

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <Badge variant="outline" className="gap-1 font-normal" aria-label={label} tabIndex={0}>
          <Icon className="size-3" aria-hidden="true" />
          <span className={compact ? "hidden sm:inline" : undefined}>{label}</span>
        </Badge>
      </TooltipTrigger>
      <TooltipContent className="break-all">{tooltip}</TooltipContent>
    </Tooltip>
  );
}

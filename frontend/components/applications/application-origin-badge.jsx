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

  // A neutral pill (`muted`), the same height and shape as the status pill beside it;
  // the icon, not a dot, says it is where the app came from rather than its state.
  // `data-slot="badge"` is passed explicitly: as the `asChild` trigger, the pill would
  // otherwise take the trigger's `data-slot="tooltip-trigger"`, and themes that style
  // pills through `[data-slot="badge"]` would skip it.
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <Badge data-slot="badge" variant="muted" className="gap-1.5 font-normal" aria-label={label} tabIndex={0}>
          <Icon className="size-3" aria-hidden="true" />
          <span className={compact ? "hidden sm:inline" : undefined}>{label}</span>
        </Badge>
      </TooltipTrigger>
      <TooltipContent className="break-all">{tooltip}</TooltipContent>
    </Tooltip>
  );
}

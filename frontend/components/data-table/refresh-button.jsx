"use client";

import { RefreshCw } from "lucide-react";
import { useTranslations } from "next-intl";
import { cn } from "@/lib/utils";
import { useRefresh } from "@/hooks/use-refresh";
import { Button } from "@/components/ui/button";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";

// Under a <NavTransitionProvider> it shares the list's pending signal; otherwise local.
export function RefreshButton({ className }) {
  const t = useTranslations("common");
  const { pending, refresh } = useRefresh();

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        {/* aria-disabled, not disabled: a disabled button drops keyboard focus. */}
        <Button
          type="button"
          variant="outline"
          size="icon"
          // 36px matches card-header clusters; toolbars of `sm` buttons pass size-8.
          className={cn("size-9 shrink-0", pending && "cursor-progress", className)}
          onClick={pending ? undefined : refresh}
          aria-disabled={pending}
          aria-busy={pending}
          aria-label={t("refresh")}
        >
          <RefreshCw className={cn("size-4", pending && "animate-spin")} />
        </Button>
      </TooltipTrigger>
      <TooltipContent>{t("refresh")}</TooltipContent>
    </Tooltip>
  );
}

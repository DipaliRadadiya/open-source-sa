"use client";

import { RefreshCw } from "lucide-react";
import { useTranslations } from "next-intl";
import { cn } from "@/lib/utils";
import { useRefresh } from "@/hooks/use-refresh";
import { Button } from "@/components/ui/button";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";

/**
 * Re-fetches the current list without a full page reload by re-running the
 * server component. Under a <NavTransitionProvider> it shares the list's
 * pending signal (so the table dims); otherwise it uses a local transition.
 */
export function RefreshButton({ className }) {
  const t = useTranslations("common");
  const { pending, refresh } = useRefresh();

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        {/* Busy, not disabled: a disabled button drops focus, so pressing it
            from the keyboard sent the reader back to the top of the page. The
            click is ignored instead while the spinner turns. */}
        <Button
          type="button"
          variant="outline"
          size="icon"
          // 36px by default, matching the card-header clusters it usually
          // sits in. A toolbar of `sm` buttons passes size-8 so the row keeps
          // one height — the same mismatch that made these read as bolted on.
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

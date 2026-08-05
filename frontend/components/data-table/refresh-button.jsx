"use client";

import { useTransition } from "react";
import { useRouter } from "next/navigation";
import { RefreshCw } from "lucide-react";
import { useTranslations } from "next-intl";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { useNavTransition } from "@/components/data-table/nav-transition";

/**
 * Re-fetches the current list without a full page reload by re-running the
 * server component. Under a <NavTransitionProvider> it shares the list's
 * pending signal (so the table dims); otherwise it uses a local transition.
 */
export function RefreshButton() {
  const t = useTranslations("common");
  const nav = useNavTransition();
  const router = useRouter();
  const [localPending, startLocal] = useTransition();

  const pending = nav ? nav.isPending : localPending;
  const refresh = nav ? nav.refresh : () => startLocal(() => router.refresh());

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        {/* Same disabled-safe wrapper as WorkerActions/ServiceActions — a
            disabled button swallows pointer events, so hover needs a span to
            hang off during the brief pending state. */}
        <span tabIndex={pending ? 0 : -1} className="inline-flex">
          <Button
            type="button"
            variant="outline"
            size="icon"
            className="size-9 shrink-0"
            onClick={refresh}
            disabled={pending}
            aria-label={t("refresh")}
          >
            <RefreshCw className={cn("size-4", pending && "animate-spin")} />
          </Button>
        </span>
      </TooltipTrigger>
      <TooltipContent>{t("refresh")}</TooltipContent>
    </Tooltip>
  );
}

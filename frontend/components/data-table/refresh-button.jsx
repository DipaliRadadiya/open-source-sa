"use client";

import { useTransition } from "react";
import { useRouter } from "next/navigation";
import { RefreshCw } from "lucide-react";
import { useTranslations } from "next-intl";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
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
    <Button
      type="button"
      variant="outline"
      size="icon"
      className="size-9 shrink-0"
      onClick={refresh}
      disabled={pending}
      aria-label={t("refresh")}
      title={t("refresh")}
    >
      <RefreshCw className={cn("size-4", pending && "animate-spin")} />
    </Button>
  );
}

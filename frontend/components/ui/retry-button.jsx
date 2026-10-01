"use client";

import { useTransition } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { Loader2, RotateCw } from "lucide-react";
import { Button } from "@/components/ui/button";

/**
 * Retry for an error boundary. `reset()` alone re-renders the cached (failed)
 * RSC payload without a request; `router.refresh()` refetches it. Both run in
 * one transition so `pending` covers the round trip.
 */
export function RetryButton({ reset }) {
  const t = useTranslations("errors");
  const router = useRouter();
  const [pending, startTransition] = useTransition();

  return (
    <Button
      variant="outline"
      disabled={pending}
      onClick={() =>
        startTransition(() => {
          router.refresh();
          reset?.();
        })
      }
    >
      {pending ? <Loader2 className="size-4 animate-spin" /> : <RotateCw className="size-4" />}
      {pending ? t("retrying") : t("retry")}
    </Button>
  );
}

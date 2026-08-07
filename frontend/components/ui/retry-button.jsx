"use client";

import { useTransition } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { Loader2, RotateCw } from "lucide-react";
import { Button } from "@/components/ui/button";

/**
 * The retry control for an error boundary.
 *
 * `reset()` on its own does nothing when the error came from server rendering:
 * it re-renders the boundary's children from the RSC payload the client
 * already has, which is the failed one, so the boundary throws again in the
 * same tick and not a single request goes out. Measured — a click produced
 * zero network activity.
 *
 * `router.refresh()` is what actually re-requests the server component;
 * `reset()` then clears the boundary so the fresh payload can render. Both
 * inside one transition, so `pending` covers the whole round trip rather than
 * leaving the button looking inert while the server is being asked again.
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

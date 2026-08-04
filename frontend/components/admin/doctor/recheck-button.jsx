"use client";

import { useTransition } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { RotateCw } from "lucide-react";
import { Button } from "@/components/ui/button";

// Doctor is read-only and not polled — re-running is just a fresh server
// render, so router.refresh() re-invokes the SSR fetch with no client state.
export function RecheckButton() {
  const t = useTranslations("doctor");
  const router = useRouter();
  const [pending, startTransition] = useTransition();

  return (
    <Button
      variant="outline"
      size="sm"
      className="shrink-0"
      disabled={pending}
      onClick={() => startTransition(() => router.refresh())}
    >
      <RotateCw className={pending ? "size-4 animate-spin" : "size-4"} />
      {t("recheck")}
    </Button>
  );
}

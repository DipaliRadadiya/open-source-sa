"use client";

import { useState } from "react";
import Link from "next/link";
import { useTranslations } from "next-intl";
import { Sparkles, ArrowRight, X } from "lucide-react";
import { Button } from "@/components/ui/button";

const DISMISS_KEY = "sv-setup-banner-dismissed";

/**
 * A gentle nudge on the dashboard while the recommended setup is incomplete, so
 * a skipped component isn't lost. Dismissible and remembered — it never nags.
 * Rendered only when `remaining > 0`.
 */
export function SetupBanner({ remaining }) {
  const t = useTranslations("setup");
  const [dismissed, setDismissed] = useState(() => {
    if (typeof window === "undefined") return false;
    return window.localStorage.getItem(DISMISS_KEY) === "1";
  });

  if (dismissed || remaining <= 0) return null;

  function dismiss() {
    setDismissed(true);
    try {
      window.localStorage.setItem(DISMISS_KEY, "1");
    } catch {
      // Private mode / storage disabled — the banner just reappears next load.
    }
  }

  return (
    <div className="flex flex-wrap items-center gap-3 rounded-xl border border-primary/30 bg-primary/[0.04] px-4 py-3">
      <Sparkles className="size-4 shrink-0 text-primary" />
      <p className="min-w-0 flex-1 text-sm">
        <span className="font-medium">{t("bannerTitle")}</span>{" "}
        <span className="text-muted-foreground">{t("bannerBody", { count: remaining })}</span>
      </p>
      <Button asChild size="sm" className="shrink-0">
        <Link href="/setup">
          {t("bannerAction")}
          <ArrowRight className="size-3.5" />
        </Link>
      </Button>
      <button
        type="button"
        onClick={dismiss}
        aria-label={t("bannerDismiss")}
        className="shrink-0 rounded-md p-1 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
      >
        <X className="size-4" />
      </button>
    </div>
  );
}

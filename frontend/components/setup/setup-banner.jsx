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
      {/* min-w-48, not min-w-0: `flex-1` gives this a basis of 0, so on a phone
          it kept shrinking to make room for the button rather than pushing it
          onto the next line — the sentence ended up one word per row. A real
          minimum is what makes flex-wrap actually wrap. */}
      <p className="min-w-48 flex-1 text-sm">
        <span className="font-medium">{t("bannerTitle")}</span>{" "}
        <span className="text-muted-foreground">{t("bannerBody", { count: remaining })}</span>
      </p>
      {/* One flex child so the action and its dismiss wrap together and stay
          on the same line as each other. */}
      <div className="ml-auto flex shrink-0 items-center gap-2">
        <Button asChild size="sm">
          <Link href="/setup">
            {t("bannerAction")}
            <ArrowRight className="size-3.5" />
          </Link>
        </Button>
        <button
          type="button"
          onClick={dismiss}
          aria-label={t("bannerDismiss")}
          className="rounded-md p-1 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
        >
          <X className="size-4" />
        </button>
      </div>
    </div>
  );
}

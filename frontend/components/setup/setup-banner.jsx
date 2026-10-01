"use client";

import { useState, useSyncExternalStore } from "react";
import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { Sparkles, ArrowRight, X } from "lucide-react";
import { Button } from "@/components/ui/button";

const DISMISS_KEY = "sv-setup-banner-dismissed";

function subscribeStorage(onChange) {
  window.addEventListener("storage", onChange);
  return () => window.removeEventListener("storage", onChange);
}

function readDismissed() {
  try {
    return window.localStorage.getItem(DISMISS_KEY) === "1";
  } catch {
    return false;
  }
}

export function SetupBanner({ remaining }) {
  const t = useTranslations("setup");
  // A hidden server snapshot keeps hydration matching, so a dismissed banner never flashes in.
  const stored = useSyncExternalStore(subscribeStorage, readDismissed, () => true);
  const [justDismissed, setJustDismissed] = useState(false);
  const dismissed = stored || justDismissed;

  if (dismissed || remaining <= 0) return null;

  function dismiss() {
    setJustDismissed(true);
    try {
      window.localStorage.setItem(DISMISS_KEY, "1");
    } catch {
      // Private mode / storage disabled — the banner just reappears next load.
    }
  }

  return (
    <div className="flex flex-wrap items-center gap-3 rounded-xl border border-primary/30 bg-primary/[0.04] px-4 py-3">
      <Sparkles className="size-4 shrink-0 text-primary" />
      {/* min-w-48, not min-w-0: flex-1 would shrink it to one word per line instead of wrapping. */}
      <p className="min-w-48 flex-1 text-sm">
        <span className="font-medium">{t("bannerTitle")}</span>{" "}
        <span className="text-muted-foreground">{t("bannerBody", { count: remaining })}</span>
      </p>
      {/* One flex child so the action and dismiss wrap together. */}
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

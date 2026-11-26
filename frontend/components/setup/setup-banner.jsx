"use client";

import { useState, useSyncExternalStore } from "react";
import Link from "@/components/ui/app-link";
import { useFormatter, useTranslations } from "next-intl";
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

export function SetupBanner({ remaining, names = [] }) {
  const t = useTranslations("setup");
  const format = useFormatter();
  // A hidden server snapshot keeps hydration matching.
  const stored = useSyncExternalStore(subscribeStorage, readDismissed, () => true);
  const [justDismissed, setJustDismissed] = useState(false);
  const dismissed = stored || justDismissed;

  if (dismissed || remaining <= 0) return null;

  function dismiss() {
    setJustDismissed(true);
    try {
      window.localStorage.setItem(DISMISS_KEY, "1");
    } catch {
      // storage blocked; ignore
    }
  }

  return (
    <div className="flex flex-wrap items-center gap-3 rounded-xl border bg-card px-4 py-3">
      <span className="flex size-8 shrink-0 items-center justify-center rounded-[2px] border">
        <Sparkles className="size-4 text-primary" />
      </span>
      <p className="min-w-48 flex-1 text-sm">
        <span className="font-medium">{t("bannerTitle")}</span>{" "}
        <span className="text-muted-foreground">
          {names.length
            ? t("bannerBodyNamed", { count: remaining, names: format.list(names, { type: "conjunction" }) })
            : t("bannerBody", { count: remaining })}
        </span>
      </p>
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
          className="flex size-8 items-center justify-center rounded-[2px] border text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
        >
          <X className="size-4" />
        </button>
      </div>
    </div>
  );
}

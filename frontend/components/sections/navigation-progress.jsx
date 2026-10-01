"use client";

import { Suspense, useEffect, useState, useSyncExternalStore } from "react";
import { usePathname, useSearchParams } from "next/navigation";
import { useTranslations } from "next-intl";
import {
  currentNavigation,
  pageChanged,
  subscribeNavigation,
} from "@/lib/browser/navigation-pending";

// A fast page change should not flash a bar or make a screen reader speak.
const SHOW_AFTER_MS = 150;

function PageChangeWatcher() {
  const pathname = usePathname();
  const search = useSearchParams().toString();

  useEffect(() => pageChanged(), [pathname, search]);

  return null;
}

/**
 * A thin bar across the top while a page loads. Links report into it
 * (components/ui/app-link.jsx); it clears when the page has changed.
 */
export function NavigationProgress() {
  const t = useTranslations("common");
  const navigation = useSyncExternalStore(
    subscribeNavigation,
    currentNavigation,
    () => null,
  );
  // Which wait has outlasted the delay. Each wait has a new number, so nothing
  // needs resetting when one ends.
  const [slowNavigation, setSlowNavigation] = useState(null);

  useEffect(() => {
    if (navigation === null) return;
    const timer = setTimeout(() => setSlowNavigation(navigation), SHOW_AFTER_MS);
    return () => clearTimeout(timer);
  }, [navigation]);

  const slow = navigation !== null && slowNavigation === navigation;

  return (
    <>
      <Suspense fallback={null}>
        <PageChangeWatcher />
      </Suspense>
      {slow ? (
        <div
          aria-hidden="true"
          className="pointer-events-none fixed inset-x-0 top-0 z-[100] h-0.5"
        >
          <div className="animate-nav-progress h-full w-full bg-primary" />
        </div>
      ) : null}
      <div role="status" aria-live="polite" className="sr-only">
        {slow ? t("loadingPage") : ""}
      </div>
    </>
  );
}

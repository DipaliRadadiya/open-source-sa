"use client";

import { Suspense, useEffect, useState, useSyncExternalStore } from "react";
import { usePathname, useSearchParams } from "next/navigation";
import { useTranslations } from "next-intl";
import {
  currentNavigation,
  pageChanged,
  subscribeNavigation,
} from "@/lib/browser/navigation-pending";

const SHOW_AFTER_MS = 150;

function PageChangeWatcher() {
  const pathname = usePathname();
  const search = useSearchParams().toString();

  useEffect(() => pageChanged(), [pathname, search]);

  return null;
}

export function NavigationProgress() {
  const t = useTranslations("common");
  const navigation = useSyncExternalStore(
    subscribeNavigation,
    currentNavigation,
    () => null,
  );
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

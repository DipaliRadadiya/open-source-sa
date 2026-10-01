"use client";

import { useEffect, useRef } from "react";

/**
 * Publishes the height of the sticky chrome as `--app-chrome` on <html>.
 *
 * The sticky cluster (header, breadcrumb band, impersonation and reboot banners)
 * has no fixed height: banners are conditional and can wrap. Anything else that
 * sticks must offset by this variable, never a hardcoded value.
 *
 * Rendered inside the cluster and measures its own parent, so it cannot drift.
 */
export function AppChromeHeight() {
  const ref = useRef(null);

  useEffect(() => {
    const cluster = ref.current?.parentElement;
    if (!cluster) return undefined;

    const publish = () => {
      document.documentElement.style.setProperty(
        "--app-chrome",
        `${Math.round(cluster.getBoundingClientRect().height)}px`,
      );
    };

    const observer = new ResizeObserver(publish);
    observer.observe(cluster);
    return () => {
      observer.disconnect();
      document.documentElement.style.removeProperty("--app-chrome");
    };
  }, []);

  return <span ref={ref} className="hidden" aria-hidden />;
}

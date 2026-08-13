"use client";

import { useEffect, useRef } from "react";

/**
 * Publishes the height of the sticky chrome as `--app-chrome` on <html>.
 *
 * The cluster at the top of the shell — header, breadcrumb band, and the
 * impersonation and reboot-required banners — is sticky and its height is not
 * fixed: the banners are conditional and either can wrap to two lines on a
 * narrow screen. Anything else that sticks has to clear it, and every hardcoded
 * offset was wrong for somebody. The Create application summary sat at `top-20`
 * and slid under the breadcrumb the moment a banner appeared.
 *
 * Rendered inside the cluster and measures its own parent, so it cannot drift
 * out of step with what it is describing.
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

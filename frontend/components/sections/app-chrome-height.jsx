"use client";

import { useEffect, useRef } from "react";

// Publishes the sticky chrome's height as `--app-chrome`. Its banners are conditional and
// wrap, so anything else that sticks must offset by this, never a hardcoded value.
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

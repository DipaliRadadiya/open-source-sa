"use client";

import { useEffect, useRef } from "react";

// Sets --app-chrome to the sticky header's height; anything else sticky must offset by it.
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

"use client";

import { useEffect, useRef } from "react";
import { usePathname } from "next/navigation";
import { useTranslations } from "next-intl";

/**
 * Keyboard entry into the panel's content and orientation after route changes.
 *
 * The first render keeps the browser's normal focus. Subsequent pathname
 * changes focus the new h1 without scrolling it away from Next's chosen
 * position. Hash-only jumps do not change the pathname, so in-page targets
 * such as Application Security keep their own focus behavior.
 */
export function PanelFocus() {
  const pathname = usePathname();
  const t = useTranslations("common");
  const initial = useRef(true);

  useEffect(() => {
    if (initial.current) {
      initial.current = false;
      return undefined;
    }

    let restore = null;
    const frame = requestAnimationFrame(() => {
      const heading = document.querySelector("#main-content h1");
      if (!heading) return;

      const previousTabIndex = heading.getAttribute("tabindex");
      heading.setAttribute("tabindex", "-1");
      heading.focus({ preventScroll: true });

      restore = () => {
        if (previousTabIndex === null) heading.removeAttribute("tabindex");
        else heading.setAttribute("tabindex", previousTabIndex);
      };
      heading.addEventListener("blur", restore, { once: true });
    });

    return () => {
      cancelAnimationFrame(frame);
      restore?.();
    };
  }, [pathname]);

  return (
    <a
      href="#main-content"
      className="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-md focus:bg-background focus:px-3 focus:py-2 focus:text-sm focus:font-medium focus:text-foreground focus:shadow-md focus:ring-2 focus:ring-ring focus:ring-offset-2"
    >
      {t("skipToContent")}
    </a>
  );
}

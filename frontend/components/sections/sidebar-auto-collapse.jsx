"use client";

import { useEffect, useRef } from "react";
import { useIsTablet } from "@/hooks/use-mobile";
import { useSidebar } from "@/components/ui/sidebar";

// Collapses to the icon rail on tablet widths, only on the transition: inside the range
// the user may still open it.
export function SidebarAutoCollapse() {
  const isTablet = useIsTablet();
  const { setOpen } = useSidebar();
  const previous = useRef(null);

  useEffect(() => {
    if (previous.current === isTablet) return;
    previous.current = isTablet;
    setOpen(!isTablet);
  }, [isTablet, setOpen]);

  return null;
}

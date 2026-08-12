"use client";

import { useEffect, useRef } from "react";
import { useIsTablet } from "@/hooks/use-mobile";
import { useSidebar } from "@/components/ui/sidebar";

/**
 * Collapses the sidebar to its icon rail on tablet widths, and expands it again
 * on the way back to desktop.
 *
 * Only on the transition, never on every render: inside the range the user is
 * still free to open it, and re-collapsing under them would make the toggle
 * look broken.
 *
 * Renders nothing — it exists to run inside SidebarProvider without editing the
 * generated primitive.
 */
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

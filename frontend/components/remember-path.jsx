"use client";

import { useEffect } from "react";
import { usePathname, useSearchParams } from "next/navigation";
import { rememberPath } from "@/lib/auth/last-path";

/**
 * Records the current screen so signing back in returns to it. Done in the
 * browser because the server has no header naming the rendered route.
 * `sessionStorage` (per tab) so each tab returns to its own screen. Mount only
 * inside authenticated shells.
 */
export function RememberPath() {
  const pathname = usePathname();
  const searchParams = useSearchParams();

  useEffect(() => {
    // Keep the query so filters survive the round trip.
    const query = searchParams.toString();
    rememberPath(query ? `${pathname}?${query}` : pathname);
  }, [pathname, searchParams]);

  return null;
}

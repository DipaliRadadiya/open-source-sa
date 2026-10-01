"use client";

import { useEffect } from "react";
import { usePathname, useSearchParams } from "next/navigation";
import { rememberPath } from "@/lib/auth/last-path";

// `sessionStorage` so each tab returns to its own screen. Mount only in authenticated shells.
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

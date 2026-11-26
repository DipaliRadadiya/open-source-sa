"use client";

import { useEffect } from "react";
import { usePathname, useSearchParams } from "next/navigation";
import { rememberPath } from "@/lib/auth/last-path";

export function RememberPath() {
  const pathname = usePathname();
  const searchParams = useSearchParams();

  useEffect(() => {
    const query = searchParams.toString();
    rememberPath(query ? `${pathname}?${query}` : pathname);
  }, [pathname, searchParams]);

  return null;
}

import { useCallback } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { useNavTransition } from "@/components/data-table/nav-transition";

/**
 * Returns a setter that merges updates into the current URL searchParams and
 * navigates (replace, no scroll). Empty/null/undefined values delete the key.
 * Pass { resetPage: true } to drop `page` back to 1 (used by search/filter).
 *
 * When rendered under a <NavTransitionProvider>, navigation is routed through
 * that shared useTransition (so controls get a common `isPending`); otherwise
 * it falls back to a plain replace.
 */
export function useSetQuery() {
  const nav = useNavTransition();
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();

  const fallback = useCallback(
    (updates, { resetPage = false } = {}) => {
      const params = new URLSearchParams(searchParams);
      for (const [key, value] of Object.entries(updates)) {
        if (value === undefined || value === null || value === "") {
          params.delete(key);
        } else {
          params.set(key, String(value));
        }
      }
      if (resetPage) params.delete("page");
      const qs = params.toString();
      router.replace(qs ? `${pathname}?${qs}` : pathname, { scroll: false });
    },
    [router, pathname, searchParams],
  );

  return nav ? nav.setQuery : fallback;
}

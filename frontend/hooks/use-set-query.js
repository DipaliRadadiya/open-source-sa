import { useCallback } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { useNavTransition } from "@/components/data-table/nav-transition";

// Empty values delete the key; `{ resetPage: true }` drops `page`. Uses the
// <NavTransitionProvider> transition when present, for a shared `isPending`.
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
      // Same rule as the provider — see nav-transition.jsx for why.
      const hadQuery = searchParams.toString() !== "";
      const navigate = hadQuery || !qs ? router.replace : router.push;
      navigate.call(router, qs ? `${pathname}?${qs}` : pathname, { scroll: false });
    },
    [router, pathname, searchParams],
  );

  return nav ? nav.setQuery : fallback;
}

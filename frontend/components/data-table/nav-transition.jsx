"use client";

import { createContext, useCallback, useContext, useTransition } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";

const NavContext = createContext(null);

export function useNavTransition() {
  return useContext(NavContext);
}

export function useNavPending() {
  return useContext(NavContext)?.isPending ?? false;
}

/**
 * Wraps a list's URL-driven controls in a single useTransition so search /
 * filter / pagination share one `isPending` signal — used to show a spinner in
 * the search box, disable pagination, and dim the table while the server
 * re-fetches. Any control using `useSetQuery` under this provider routes
 * through the shared transition automatically.
 */
export function NavTransitionProvider({ children }) {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const [isPending, startTransition] = useTransition();

  const setQuery = useCallback(
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
      /*
       * `push` the first time the URL gains a query, `replace` after that, so
       * Back clears the filters once instead of stepping through keystrokes or
       * leaving the page.
       */
      const hadQuery = searchParams.toString() !== "";
      const navigate = hadQuery || !qs ? router.replace : router.push;
      startTransition(() =>
        navigate.call(router, qs ? `${pathname}?${qs}` : pathname, { scroll: false }),
      );
    },
    [router, pathname, searchParams],
  );

  // Re-fetch via the server component, sharing the same pending signal.
  const refresh = useCallback(
    () => startTransition(() => router.refresh()),
    [router],
  );

  return (
    <NavContext.Provider value={{ setQuery, refresh, isPending }}>
      {children}
    </NavContext.Provider>
  );
}

"use client";

import { createContext, useContext, useEffect, useState } from "react";

/**
 * Lets a detail page put its own name in the header breadcrumb.
 *
 * The breadcrumb is built from the nav catalog, which only knows section names
 * — so every database read "Server › Database" and nothing said WHICH one you
 * had open. Context rather than a store: it is one entry, and the panel has no
 * client state library.
 */
const PageCrumbContext = createContext(null);

export function PageCrumbProvider({ children }) {
  const [crumb, setCrumb] = useState(null);
  return (
    <PageCrumbContext.Provider value={{ crumb, setCrumb }}>
      {children}
    </PageCrumbContext.Provider>
  );
}

export function usePageCrumb() {
  // Null outside the app shell: the header is the only consumer, and a missing
  // provider should mean "no extra crumb" rather than throw.
  return useContext(PageCrumbContext) ?? { crumb: null, setCrumb: () => {} };
}

/**
 * Rendered by a detail page or by the application layout. Clears itself on the
 * way out, so navigating back to the list does not leave a stale name behind.
 *
 * `href` makes the entry a link — the application name is an ancestor of every
 * screen inside that site, not a dead end. `mono` is for entries that are
 * identifiers you might retype (a database name), not for display names.
 */
export function PageCrumb({ children, href, mono = false }) {
  const { setCrumb } = usePageCrumb();

  useEffect(() => {
    // Built inside the effect so a fresh object each render cannot re-trigger it.
    setCrumb({ label: children, href, mono });
    return () => setCrumb(null);
  }, [children, href, mono, setCrumb]);

  return null;
}

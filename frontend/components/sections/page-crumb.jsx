"use client";

import { createContext, useContext, useEffect, useState } from "react";

/**
 * Lets a detail page put its own name in the header breadcrumb.
 *
 * The breadcrumb is built from the nav catalog, which only knows section names
 * — so every database read "Server › Database" and nothing said WHICH one you
 * had open. Context rather than a store: it is one string, and the panel has no
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
 * Rendered by a detail page. Clears itself on the way out, so navigating back
 * to the list does not leave a stale name in the header.
 */
export function PageCrumb({ children }) {
  const { setCrumb } = usePageCrumb();

  useEffect(() => {
    setCrumb(children);
    return () => setCrumb(null);
  }, [children, setCrumb]);

  return null;
}

"use client";

import { createContext, useContext, useEffect, useState } from "react";

/**
 * Lets a detail page put its own name (e.g. which database) in the header
 * breadcrumb, which otherwise only knows section names from the nav catalog.
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
  // Null outside the app shell: a missing provider means "no extra crumb", not a
  // throw.
  return useContext(PageCrumbContext) ?? { crumb: null, setCrumb: () => {} };
}

/**
 * Rendered by a detail page or the application layout; clears itself on the way
 * out so no stale name remains.
 *
 * `href` makes the entry a link (the application name is an ancestor of every
 * screen in the site). `mono` is for identifiers you might retype (a database
 * name). `root` replaces the trail entirely, for pages not in the sidebar (e.g.
 * Account, reached from the user menu).
 */
export function PageCrumb({ children, href, mono = false, root = false }) {
  const { setCrumb } = usePageCrumb();

  useEffect(() => {
    // Built inside the effect so a fresh object each render cannot re-trigger it.
    setCrumb({ label: children, href, mono, root });
    return () => setCrumb(null);
  }, [children, href, mono, root, setCrumb]);

  return null;
}

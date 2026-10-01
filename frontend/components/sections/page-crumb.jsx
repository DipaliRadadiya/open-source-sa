"use client";

import { createContext, useContext, useEffect, useState } from "react";

// The header breadcrumb otherwise only knows section names from the nav catalog.
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
  // Null outside the app shell means "no extra crumb", not a throw.
  return useContext(PageCrumbContext) ?? { crumb: null, setCrumb: () => {} };
}

// Clears itself on unmount. `mono` is for retypeable identifiers; `root` replaces
// the trail, for pages not in the sidebar.
export function PageCrumb({ children, href, mono = false, root = false }) {
  const { setCrumb } = usePageCrumb();

  useEffect(() => {
    // Built inside the effect so a fresh object each render cannot re-trigger it.
    setCrumb({ label: children, href, mono, root });
    return () => setCrumb(null);
  }, [children, href, mono, root, setCrumb]);

  return null;
}

"use client";

import { createContext, useContext, useEffect, useState } from "react";

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
  return useContext(PageCrumbContext) ?? { crumb: null, setCrumb: () => {} };
}

export function PageCrumb({ children, href, mono = false, root = false }) {
  const { setCrumb } = usePageCrumb();

  useEffect(() => {
    // Built inside the effect so a new object each render doesn't re-trigger it.
    setCrumb({ label: children, href, mono, root });
    return () => setCrumb(null);
  }, [children, href, mono, root, setCrumb]);

  return null;
}

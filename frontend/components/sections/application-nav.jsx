"use client";

import { createContext, useContext, useEffect, useState } from "react";

/**
 * Carries ONE application's nav catalog from its layout up to the sidebar.
 *
 * The sidebar lives in the `(app)` layout and never sees the `[application]`
 * param, so the application layout fetches the catalog and hands it over, like
 * PageCrumb. Only `?level=application&application_id=…` applies the site-type
 * filter (e.g. static sites have no PHP settings).
 */
const ApplicationNavContext = createContext(null);

export function ApplicationNavProvider({ children }) {
  const [state, setState] = useState({ items: null, resolved: false, application: null, gitProvider: null });
  return (
    <ApplicationNavContext.Provider value={{ ...state, setState }}>
      {children}
    </ApplicationNavContext.Provider>
  );
}

export function useApplicationNav() {
  return (
    useContext(ApplicationNavContext) ?? { items: null, resolved: false, setState: () => {} }
  );
}

/**
 * Rendered by the application layout. Clears on the way out so a server page
 * never inherits the last application's menu.
 */
export function ApplicationNav({ items, application = null, gitProvider = null }) {
  const { setState } = useApplicationNav();

  useEffect(() => {
    // Set even when `items` is null: that is the answer for a deleted site, and the
    // sidebar needs it to avoid rendering links that 404.
    setState({ items, resolved: true, application, gitProvider });
    return () => setState({ items: null, resolved: false, application: null, gitProvider: null });
  }, [items, application, gitProvider, setState]);

  return null;
}

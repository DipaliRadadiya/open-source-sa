"use client";

import { createContext, useContext, useEffect, useState } from "react";

/**
 * Carries ONE application's nav catalog from its layout up to the sidebar.
 *
 * The sidebar lives in the `(app)` layout, so it never sees the `[application]`
 * param and cannot fetch that catalog itself. The application layout can, and
 * this hands it over the same way PageCrumb hands over a name — the two are
 * siblings under the same provider.
 *
 * It matters because the backend applies a filter the client cannot: what the
 * SITE TYPE supports. A static site has no PHP settings and WordPress has no
 * Deployment, and only `?level=application&application_id=…` knows that.
 */
const ApplicationNavContext = createContext(null);

export function ApplicationNavProvider({ children }) {
  const [state, setState] = useState({ items: null, resolved: false });
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
export function ApplicationNav({ items }) {
  const { setState } = useApplicationNav();

  useEffect(() => {
    // Rendered even when `items` is null — that IS the answer for a site that no
    // longer exists, and the sidebar needs to hear it. Saying nothing left the
    // sidebar to guess, and it guessed a full menu of links that all 404.
    setState({ items, resolved: true });
    return () => setState({ items: null, resolved: false });
  }, [items, setState]);

  return null;
}

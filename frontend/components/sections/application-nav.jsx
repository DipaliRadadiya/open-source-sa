"use client";

import { createContext, useContext, useEffect, useState } from "react";

// Carries one application's nav catalog from its layout up to the sidebar, which never
// sees the `[application]` param.
const ApplicationNavContext = createContext(null);

export function ApplicationNavProvider({ children }) {
  const [state, setState] = useState({ items: null, resolved: false, application: null, gitProvider: null, iconDataUri: null });
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

// Clears on the way out so a server page never inherits the last application's menu.
export function ApplicationNav({ items, application = null, gitProvider = null, iconDataUri = null }) {
  const { setState } = useApplicationNav();

  useEffect(() => {
    // Set even when `items` is null: that is the answer for a deleted site, and the
    // sidebar needs it to avoid rendering links that 404.
    setState({ items, resolved: true, application, gitProvider, iconDataUri });
    return () => setState({ items: null, resolved: false, application: null, gitProvider: null, iconDataUri: null });
  }, [items, application, gitProvider, iconDataUri, setState]);

  return null;
}

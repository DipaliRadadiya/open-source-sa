"use client";

import { createContext, useContext, useEffect, useState } from "react";

// The sidebar never gets the [application] param, so the layout passes the nav up.
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

// Cleared on unmount so a server page doesn't keep the last application's menu.
export function ApplicationNav({ items, application = null, gitProvider = null, iconDataUri = null }) {
  const { setState } = useApplicationNav();

  useEffect(() => {
    setState({ items, resolved: true, application, gitProvider, iconDataUri });
    return () => setState({ items: null, resolved: false, application: null, gitProvider: null, iconDataUri: null });
  }, [items, application, gitProvider, iconDataUri, setState]);

  return null;
}

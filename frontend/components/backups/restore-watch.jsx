"use client";

import { createContext, useContext, useState } from "react";
import { ActiveRestore } from "@/components/backups/active-restore";

// A restore started in this tab shows at once; `initial` covers reloads and other tabs.
// One banner here, not per screen, so the server's copy of the same restore never duplicates it.
const RestoreWatchContext = createContext({ active: null, start: () => {} });

export function RestoreWatch({ initial = null, children }) {
  const [started, setStarted] = useState(null);

  // The client's copy wins; `initial` is the fallback for restores not started in this tab.
  const active = started ?? initial;

  return (
    <RestoreWatchContext.Provider value={{ active, start: setStarted }}>
      {/* Scroll only for a restore started in this tab, never for `initial`. */}
      {active ? (
        <ActiveRestore key={active.id} restore={active} scrollIntoView={Boolean(started)} />
      ) : null}
      {children}
    </RestoreWatchContext.Provider>
  );
}

export function useRestoreWatch() {
  return useContext(RestoreWatchContext);
}

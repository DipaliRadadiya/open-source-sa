"use client";

import { createContext, useContext, useState } from "react";
import { ActiveRestore } from "@/components/backups/active-restore";

/**
 * The one place a running restore is shown. A restore started in this tab is
 * held in client state and shown immediately; `initial` covers reloads, other
 * tabs and restores started by someone else. Rendering the banner here (not
 * per screen) prevents duplicates once the server reports the same restore.
 */
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

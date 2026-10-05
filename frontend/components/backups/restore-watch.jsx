"use client";

import { createContext, useContext, useState } from "react";
import { ActiveRestore } from "@/components/backups/active-restore";

// A restore started in this tab shows at once; `initial` covers reloads and other tabs.
// One banner here, not per screen, so the server's copy of the same restore never duplicates it.
const RestoreWatchContext = createContext({ active: null, start: () => {} });

export function RestoreWatch({ initial = null, children }) {
  const [started, setStarted] = useState(null);
  // The banner polls; its latest status is fed back here, or a restore started in this
  // tab would stay "pending" for every Restore/Undo/Run backup check until a reload.
  const [statusById, setStatusById] = useState({});

  // The client's copy wins; `initial` is the fallback for restores not started in this tab.
  const base = started ?? initial;
  const active = base ? { ...base, status: statusById[base.id] ?? base.status } : null;

  return (
    <RestoreWatchContext.Provider value={{ active, start: setStarted }}>
      {/* Scroll only for a restore started in this tab, never for `initial`. */}
      {active ? (
        <ActiveRestore
          key={active.id}
          restore={active}
          restoredSafetyCopy={Boolean(active.restored_safety_copy)}
          scrollIntoView={Boolean(started)}
          onStatusChange={(status) => setStatusById((current) => ({ ...current, [active.id]: status }))}
        />
      ) : null}
      {children}
    </RestoreWatchContext.Provider>
  );
}

export function useRestoreWatch() {
  return useContext(RestoreWatchContext);
}

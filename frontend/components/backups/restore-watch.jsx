"use client";

import { createContext, useContext, useState } from "react";
import { ActiveRestore } from "@/components/backups/active-restore";

const RestoreWatchContext = createContext({ active: null, start: () => {} });

export function RestoreWatch({ initial = null, children }) {
  const [started, setStarted] = useState(null);
  const [statusById, setStatusById] = useState({});

  const base = started ?? initial;
  const active = base ? { ...base, status: statusById[base.id] ?? base.status } : null;

  return (
    <RestoreWatchContext.Provider value={{ active, start: setStarted }}>
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

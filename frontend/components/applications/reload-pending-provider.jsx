"use client";

import { createContext, useContext, useEffect, useState } from "react";
import {
  RELOAD_PENDING_EVENT,
  newReloadToken,
  readReloadPending,
  recordsForUser,
  reloadReference,
  withReloadPending,
  writeReloadPending,
} from "@/lib/applications/reload-pending";

const ReloadPendingContext = createContext(null);

// `initial` is read from the cookie on the server, so a hard reload never shows
// a protection as live before the browser has caught up. Later props come from a
// refresh that may have started before the last cookie write, so the browser wins.
export function ReloadPendingProvider({ userId, canManageServices = false, initial = [], children }) {
  const [records, setRecords] = useState(initial);
  const initialKey = JSON.stringify(initial);
  const [seenKey, setSeenKey] = useState(initialKey);
  if (seenKey !== initialKey) {
    setSeenKey(initialKey);
    setRecords(typeof document === "undefined" ? initial : recordsForUser(readReloadPending(), userId));
  }

  // Another tab or a service action can change the cookie.
  useEffect(() => {
    const sync = () => setRecords(recordsForUser(readReloadPending(), userId));
    const onVisible = () => document.visibilityState === "visible" && sync();
    window.addEventListener(RELOAD_PENDING_EVENT, sync);
    window.addEventListener("focus", sync);
    document.addEventListener("visibilitychange", onVisible);
    return () => {
      window.removeEventListener(RELOAD_PENDING_EVENT, sync);
      window.removeEventListener("focus", sync);
      document.removeEventListener("visibilitychange", onVisible);
    };
  }, [userId]);

  function mark(appId, error) {
    writeReloadPending(
      withReloadPending(readReloadPending(), {
        userId,
        appId,
        reference: reloadReference(error),
        token: newReloadToken(),
      }),
    );
  }

  // Read at call time: a 200 save does not prove the web server reloaded.
  function stillPending(appId) {
    return recordsForUser(readReloadPending(), userId).some((record) => record.a === String(appId));
  }

  return (
    <ReloadPendingContext.Provider value={{ records, canManageServices, mark, stillPending }}>
      {children}
    </ReloadPendingContext.Provider>
  );
}

// A watch-mode summary describes what is running, not merely what was saved.
// The server-rendered child stays hidden too: the provider is seeded from the cookie.
export function HideWhileReloadPending({ appId, children }) {
  return useReloadPending(appId) ? null : children;
}

/** The application's unapplied save, or null. */
export function useReloadPending(appId) {
  const context = useContext(ReloadPendingContext);
  return context?.records.find((record) => record.a === String(appId)) ?? null;
}

export function useReloadPendingActions() {
  const context = useContext(ReloadPendingContext);
  return {
    mark: context?.mark ?? (() => {}),
    stillPending: context?.stillPending ?? (() => false),
    canManageServices: context?.canManageServices ?? false,
  };
}

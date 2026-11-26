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

// initial comes from the cookie on the server, so a hard reload can't show a protection as live too early.
export function ReloadPendingProvider({ userId, canManageServices = false, initial = [], children }) {
  const [records, setRecords] = useState(initial);
  const initialKey = JSON.stringify(initial);
  const [seenKey, setSeenKey] = useState(initialKey);
  if (seenKey !== initialKey) {
    setSeenKey(initialKey);
    setRecords(typeof document === "undefined" ? initial : recordsForUser(readReloadPending(), userId));
  }

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

  function stillPending(appId) {
    return recordsForUser(readReloadPending(), userId).some((record) => record.a === String(appId));
  }

  return (
    <ReloadPendingContext.Provider value={{ records, canManageServices, mark, stillPending }}>
      {children}
    </ReloadPendingContext.Provider>
  );
}

export function HideWhileReloadPending({ appId, children }) {
  return useReloadPending(appId) ? null : children;
}

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

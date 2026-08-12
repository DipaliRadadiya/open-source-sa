"use client";

import { createContext, useContext, useState } from "react";
import { ActiveRestore } from "@/components/backups/active-restore";

/**
 * The one place a running restore is shown, and the way any screen under
 * Backups can hand it one the instant it starts.
 *
 * The banner used to live in the layout alone, seeded by a server fetch. That
 * made starting a restore feel like nothing had happened: the dialog closed,
 * the list looked untouched, and the banner only turned up once a server
 * round-trip had been and gone — reliably enough that the honest instruction
 * was "reload the page".
 *
 * So the started restore is kept here in client state and shown immediately,
 * while `initial` keeps working for a reload, a second tab, or a restore
 * somebody else began. Rendering the banner inside the provider — rather than
 * letting each screen render its own — is what stops two of them appearing
 * once the server catches up and reports the same restore.
 */
const RestoreWatchContext = createContext({ active: null, start: () => {} });

export function RestoreWatch({ initial = null, children }) {
  const [started, setStarted] = useState(null);

  // The client's copy wins: it exists from the moment the API answers, and the
  // progress component polls it by id from there. `initial` is the fallback
  // for everyone who did not press the button in this tab.
  const active = started ?? initial;

  return (
    <RestoreWatchContext.Provider value={{ active, start: setStarted }}>
      {active ? <ActiveRestore key={active.id} restore={active} /> : null}
      {children}
    </RestoreWatchContext.Provider>
  );
}

export function useRestoreWatch() {
  return useContext(RestoreWatchContext);
}

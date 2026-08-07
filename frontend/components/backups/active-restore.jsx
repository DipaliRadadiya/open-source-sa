"use client";

import { useState } from "react";
import { RestoreProgress } from "@/components/backups/restore-progress";

/**
 * The restore currently rewriting a site, seeded from the server.
 *
 * A thin client wrapper so the layout — a server component — can still hand
 * the polling component something to start from. Once it reaches a terminal
 * state the component keeps showing the outcome (and the undo) until the user
 * dismisses it, rather than vanishing the moment the last poll lands.
 */
export function ActiveRestore({ restore, applicationDomain }) {
  const [dismissed, setDismissed] = useState(false);
  if (dismissed) return null;

  return (
    <RestoreProgress
      restore={restore}
      // Falls back to the domain joined onto the restore row. Without this the
      // server-level banner passed nothing, so "Undo this restore" opened a
      // dialog whose typed-domain check compared against `undefined` and could
      // never be satisfied — the undo was unusable exactly where it mattered.
      applicationDomain={applicationDomain ?? restore?.application_domain}
      onDismiss={() => setDismissed(true)}
    />
  );
}

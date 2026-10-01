import { useEffect, useRef, useState } from "react";
import { RestoreProgress } from "@/components/backups/restore-progress";
import { RESTORE_IN_FLIGHT } from "@/lib/schemas/backup";
import { rememberDismissedRestore } from "@/lib/backups/dismissed-restores";

/**
 * The restore currently rewriting a site, seeded from the server. Keeps
 * showing the outcome (and the undo) after a terminal state until dismissed.
 */
export function ActiveRestore({ restore, applicationDomain, scrollIntoView = false,
  restoredSafetyCopy = false,
  onStatusChange,
}) {
  const [dismissed, setDismissed] = useState(false);
  // Dismissing a finished restore is remembered; hiding a running one is not,
  // so its outcome and Undo still show up.
  const [latest, setLatest] = useState({ id: restore?.id, status: restore?.status });
  const box = useRef(null);

  // Restore is pressed from a row far below this banner, so scroll it into view.
  // Only when the restore was started here, not on every page load.
  useEffect(() => {
    if (!scrollIntoView) return;
    box.current?.scrollIntoView({ behavior: "smooth", block: "start" });
  }, [scrollIntoView]);

  if (dismissed) return null;

  return (
    <div ref={box} className="scroll-mt-[calc(var(--app-chrome,7rem)_+_1rem)]">
    <RestoreProgress
      restore={restore}
      // Falls back to the domain on the restore row; without it the undo's
      // typed-domain check could never be satisfied.
      applicationDomain={applicationDomain ?? restore?.application_domain}
      restoredSafetyCopy={restoredSafetyCopy}
      onStatusChange={(status, id) => {
        setLatest({ id: id ?? latest.id, status });
        onStatusChange?.(status);
      }}
      onDismiss={() => {
        if (!RESTORE_IN_FLIGHT.includes(latest.status)) rememberDismissedRestore(latest.id);
        setDismissed(true);
      }}
    />
    </div>
  );
}

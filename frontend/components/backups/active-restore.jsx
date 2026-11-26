import { useEffect, useRef, useState } from "react";
import { RestoreProgress } from "@/components/backups/restore-progress";
import { RESTORE_IN_FLIGHT } from "@/lib/schemas/backup";
import { rememberDismissedRestore } from "@/lib/backups/dismissed-restores";

export function ActiveRestore({ restore, applicationDomain, scrollIntoView = false,
  subject = "application",
  database = null,
  restoredSafetyCopy = false,
  onStatusChange,
}) {
  const [dismissed, setDismissed] = useState(false);
  const [latest, setLatest] = useState({ id: restore?.id, status: restore?.status });
  const box = useRef(null);

  useEffect(() => {
    if (!scrollIntoView) return;
    box.current?.scrollIntoView({ behavior: "smooth", block: "start" });
  }, [scrollIntoView]);

  if (dismissed) return null;

  return (
    <div ref={box} className="scroll-mt-[calc(var(--app-chrome,7rem)_+_1rem)]">
    <RestoreProgress
      restore={restore}
      // Without the domain the undo's typed check could never pass.
      applicationDomain={applicationDomain ?? restore?.application_domain}
      restoredSafetyCopy={restoredSafetyCopy}
      subject={subject}
      database={database}
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

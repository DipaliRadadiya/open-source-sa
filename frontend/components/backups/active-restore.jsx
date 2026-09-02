import { useEffect, useRef, useState } from "react";
import { RestoreProgress } from "@/components/backups/restore-progress";

/**
 * The restore currently rewriting a site, seeded from the server.
 *
 * A thin client wrapper so the layout — a server component — can still hand
 * the polling component something to start from. Once it reaches a terminal
 * state the component keeps showing the outcome (and the undo) until the user
 * dismisses it, rather than vanishing the moment the last poll lands.
 */
export function ActiveRestore({ restore, applicationDomain, scrollIntoView = false }) {
  const [dismissed, setDismissed] = useState(false);
  const box = useRef(null);

  // The banner lives at the top of the page, but Restore is pressed from a row
  // partway down a table — so the dialog would close onto an apparently
  // unchanged screen while the thing that changed sat above the fold.
  //
  // Only when the restore was started here: on a plain page load the banner is
  // already at the top, and yanking the viewport on every visit would be worse
  // than the problem.
  useEffect(() => {
    if (!scrollIntoView) return;
    box.current?.scrollIntoView({ behavior: "smooth", block: "start" });
  }, [scrollIntoView]);

  if (dismissed) return null;

  return (
    <div ref={box} className="scroll-mt-[calc(var(--app-chrome,7rem)_+_1rem)]">
    <RestoreProgress
      restore={restore}
      // Falls back to the domain joined onto the restore row. Without this the
      // server-level banner passed nothing, so "Undo this restore" opened a
      // dialog whose typed-domain check compared against `undefined` and could
      // never be satisfied — the undo was unusable exactly where it mattered.
      applicationDomain={applicationDomain ?? restore?.application_domain}
      onDismiss={() => setDismissed(true)}
    />
    </div>
  );
}

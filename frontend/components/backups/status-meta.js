import {
  CircleAlert,
  CircleCheck,
  CircleHelp,
  CircleSlash,
  Clock,
  Loader2,
  PauseCircle,
  ShieldCheck,
  ShieldQuestion,
} from "lucide-react";

/**
 * Every icon and colour the Backups feature uses to describe a state, in one
 * place.
 *
 * This was four copies. Two were byte-identical (the coverage map, duplicated
 * between the desktop table and the mobile cards — the same list, drawn twice,
 * free to drift the moment one was edited). The other two disagreed: a failed
 * BACKUP showed `CircleAlert` and a failed RESTORE showed `CircleX`, so one
 * feature drew the same word in the same red with two different glyphs.
 *
 * Backups and restores have different status vocabularies (`verified` vs
 * `succeeded`), so each maps its own words onto a shared set of outcomes
 * rather than repeating the icon choice. Adding a status means adding one
 * line, and it cannot be given a new look by accident.
 */
export const OUTCOME = {
  ok: { icon: CircleCheck, variant: "success" },
  failed: { icon: CircleAlert, variant: "destructive" },
  // In flight. Not "warning" — nothing is wrong, it simply has not finished,
  // and colouring it amber would send people looking for a problem.
  inFlight: { icon: Loader2, variant: "outline", spin: true },
  waiting: { icon: Clock, variant: "outline" },
  unknown: { icon: CircleHelp, variant: "outline" },
};

/** `BackupResource.status` → outcome. */
export const BACKUP_OUTCOME = {
  verified: "ok",
  failed: "failed",
  running: "inFlight",
  verifying: "inFlight",
  pending: "waiting",
};

/** `RestoreResource.status` → outcome. */
export const RESTORE_OUTCOME = {
  succeeded: "ok",
  failed: "failed",
  running: "inFlight",
  pending: "waiting",
};

export function outcomeOf(map, status) {
  return OUTCOME[map[status]] ?? OUTCOME.unknown;
}

/**
 * Whether a site is covered, which is a different question from how its last
 * run went — hence its own map. `paused` is the dangerous middle: configured,
 * but backing nothing up.
 */
export const COVERAGE_STATE = {
  protected: { icon: ShieldCheck, variant: "success" },
  // A schedule that runs and fails is not protection. It read `protected`,
  // green, with a six-pixel red dot beside the timestamp as the only sign —
  // the badge said the site was safe while its newest evidence said the
  // opposite.
  failing: { icon: CircleAlert, variant: "destructive" },
  paused: { icon: PauseCircle, variant: "warning" },
  unprotected: { icon: CircleSlash, variant: "destructive" },
  unknown: { icon: ShieldQuestion, variant: "outline" },
};

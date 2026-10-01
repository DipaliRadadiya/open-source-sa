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

// Backups and restores use different status words (`verified` vs `succeeded`),
// so each maps onto these shared outcomes.
export const OUTCOME = {
  ok: { icon: CircleCheck, variant: "success" },
  failed: { icon: CircleAlert, variant: "destructive" },
  // In flight: not "warning", since nothing is wrong yet.
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

// Coverage, not last-run outcome. `paused` is the dangerous one: configured,
// but backing nothing up.
export const COVERAGE_STATE = {
  protected: { icon: ShieldCheck, variant: "success" },
  // A schedule that runs and fails is not protection.
  failing: { icon: CircleAlert, variant: "destructive" },
  paused: { icon: PauseCircle, variant: "warning" },
  unprotected: { icon: CircleSlash, variant: "destructive" },
  unknown: { icon: ShieldQuestion, variant: "outline" },
};

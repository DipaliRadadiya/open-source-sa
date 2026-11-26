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

export const OUTCOME = {
  ok: { icon: CircleCheck, variant: "success" },
  failed: { icon: CircleAlert, variant: "destructive" },
  inFlight: { icon: Loader2, variant: "outline", spin: true },
  waiting: { icon: Clock, variant: "outline" },
  unknown: { icon: CircleHelp, variant: "outline" },
};

export const BACKUP_OUTCOME = {
  verified: "ok",
  failed: "failed",
  running: "inFlight",
  verifying: "inFlight",
  pending: "waiting",
};

export const RESTORE_OUTCOME = {
  succeeded: "ok",
  failed: "failed",
  running: "inFlight",
  pending: "waiting",
};

export function outcomeOf(map, status) {
  return OUTCOME[map[status]] ?? OUTCOME.unknown;
}

export const COVERAGE_STATE = {
  protected: { icon: ShieldCheck, variant: "success" },
  failing: { icon: CircleAlert, variant: "destructive" },
  paused: { icon: PauseCircle, variant: "warning" },
  unprotected: { icon: CircleSlash, variant: "destructive" },
  unknown: { icon: ShieldQuestion, variant: "outline" },
};

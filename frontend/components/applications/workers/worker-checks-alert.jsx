"use client";

import { TriangleAlert, Info, OctagonAlert } from "lucide-react";

// Every case documented against a real failure mode (queue:restart silently
// no-op'ing on the array cache driver being the one the API explicitly calls
// out to surface prominently) — rendered as its own banner, not a tooltip.
const SEVERITY_META = {
  warning: {
    icon: TriangleAlert,
    className: "border-warning/40 bg-warning/5 text-warning",
  },
  error: {
    icon: OctagonAlert,
    className: "border-destructive/40 bg-destructive/5 text-destructive",
  },
  info: {
    icon: Info,
    className: "border-primary/30 bg-primary/5 text-foreground",
  },
};

export function WorkerChecksAlert({ checks = [] }) {
  if (checks.length === 0) return null;

  return (
    <div className="space-y-2">
      {checks.map((check) => {
        const meta = SEVERITY_META[check.severity] ?? SEVERITY_META.warning;
        const Icon = meta.icon;
        return (
          <div
            key={check.code}
            role="alert"
            className={`flex items-start gap-2 rounded-lg border px-3 py-2.5 text-sm ${meta.className}`}
          >
            <Icon className="mt-0.5 size-4 shrink-0" />
            <div className="space-y-0.5">
              <p className="font-medium">{check.title}</p>
              {check.detail ? <p className="opacity-90">{check.detail}</p> : null}
            </div>
          </div>
        );
      })}
    </div>
  );
}

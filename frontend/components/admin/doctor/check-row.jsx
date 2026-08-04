import { CircleCheck, TriangleAlert, CircleX, Wrench } from "lucide-react";
import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";

// Status → how the row reads: icon (identity of the outcome), pill label, and
// card tint. Colour always carries meaning here, never decoration.
const META = {
  pass: { Icon: CircleCheck, tint: "text-success", chip: "bg-success/10", pill: "success", card: "" },
  warn: {
    Icon: TriangleAlert,
    tint: "text-warning",
    chip: "bg-warning/10",
    pill: "warning",
    card: "border-warning/30 bg-warning/[0.03]",
  },
  fail: {
    Icon: CircleX,
    tint: "text-destructive",
    chip: "bg-destructive/10",
    pill: "destructive",
    card: "border-destructive/30 bg-destructive/[0.03]",
  },
};

export function CheckRow({ check, labels }) {
  const meta = META[check.status] ?? META.warn;
  const { Icon } = meta;
  const needsAttention = check.status !== "pass";

  return (
    <div className={cn("rounded-2xl border bg-card p-4 shadow-sm transition-colors", meta.card)}>
      <div className="flex items-start gap-4">
        <span className={cn("flex size-10 shrink-0 items-center justify-center rounded-xl", meta.chip)}>
          <Icon className={cn("size-5", meta.tint)} aria-hidden />
        </span>

        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <p className="font-medium leading-tight">{check.title}</p>
            <Badge variant={meta.pill} className="font-normal">
              {labels[check.status]}
            </Badge>
          </div>

          {/* Raw evidence for an operator — a version, path or unit name.
              Untranslated by design, so it's shown monospace and muted. */}
          {check.detail ? (
            <p className="mt-1.5 break-words font-mono text-xs leading-5 text-muted-foreground">
              {check.detail}
            </p>
          ) : null}

          {/* Remediation, only when the check needs attention. */}
          {needsAttention && check.fix ? (
            <p className="mt-2 flex items-start gap-2 rounded-lg border bg-muted/40 px-3 py-2 text-sm">
              <Wrench className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden />
              <span>{check.fix}</span>
            </p>
          ) : null}
        </div>
      </div>
    </div>
  );
}

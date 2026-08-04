"use client";

import { useTranslations } from "next-intl";
import { Check, X } from "lucide-react";
import { cn } from "@/lib/utils";

// The preflight gate: each check must pass before an update can start. Known
// keys get a friendly localized name; an unknown future key falls back to the
// raw key so the list never breaks. `clean_working_tree` fails closed when the
// tree state is unknown (a forced checkout would discard uncommitted work).
export function PreflightList({ checks }) {
  const t = useTranslations("panelUpdate");
  if (!checks.length) return null;

  const name = (key) => (t.has(`preflight.${key}`) ? t(`preflight.${key}`) : key);

  return (
    <div className="space-y-2">
      <p className="text-sm font-medium">{t("preflightTitle")}</p>
      <ul className="space-y-1.5">
        {checks.map((c) => (
          <li key={c.key} className="flex items-start gap-2 text-sm">
            {c.passed ? (
              <Check className="mt-0.5 size-4 shrink-0 text-success" aria-hidden />
            ) : (
              <X className="mt-0.5 size-4 shrink-0 text-destructive" aria-hidden />
            )}
            <span className={cn(!c.passed && "text-foreground")}>
              {name(c.key)}
              {c.detail ? (
                <span className="ml-1 font-mono text-xs text-muted-foreground">({c.detail})</span>
              ) : null}
            </span>
          </li>
        ))}
      </ul>
    </div>
  );
}

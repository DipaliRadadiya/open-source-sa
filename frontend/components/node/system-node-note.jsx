import { getTranslations } from "next-intl/server";
import { Info } from "lucide-react";

export async function SystemNodeNote({ system, versions = [] }) {
  if (!system?.version) return null;
  if (system.path && (versions ?? []).some((entry) => entry.path === system.path)) return null;
  const t = await getTranslations("node");

  return (
    <p className="flex items-start gap-2 rounded-lg border bg-muted/30 px-4 py-3 text-sm text-muted-foreground">
      <Info className="mt-0.5 size-4 shrink-0" />
      {/* break-all: the path is one long token and would overflow on a phone. */}
      <span className="min-w-0">
        {t("system.note", { version: system.version })}
        {system.path ? (
          <span className="ml-1 font-mono text-xs break-all">{system.path}</span>
        ) : null}
      </span>
    </p>
  );
}

import { getTranslations } from "next-intl/server";
import { Info } from "lucide-react";

// A note, not a card: the panel did not install this Node and will not touch it.
export async function SystemNodeNote({ system, versions = [] }) {
  if (!system?.version) return null;
  // The server's `node` is often one of the panel's own versions (the installer
  // links it); then it is already the row above.
  if (system.path && (versions ?? []).some((entry) => entry.path === system.path)) return null;
  const t = await getTranslations("node");

  return (
    <p className="flex items-start gap-2 rounded-lg border bg-muted/30 px-4 py-3 text-sm text-muted-foreground">
      <Info className="mt-0.5 size-4 shrink-0" />
      {/* min-w-0 + break-all: the path is one long token with no break opportunities,
          which would otherwise overflow the card on a phone. */}
      <span className="min-w-0">
        {t("system.note", { version: system.version })}
        {system.path ? (
          <span className="ml-1 font-mono text-xs break-all">{system.path}</span>
        ) : null}
      </span>
    </p>
  );
}

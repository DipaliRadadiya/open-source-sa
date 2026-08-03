import { getTranslations } from "next-intl/server";
import { Info } from "lucide-react";

/**
 * The Node that was already on the machine.
 *
 * It gets a note rather than a card: it has no controls, because the panel did
 * not install it and will not touch it. Saying so is the point — otherwise
 * someone who typed `node -v` over SSH and saw a version sees an empty page
 * here and assumes the panel is broken.
 */
export async function SystemNodeNote({ system }) {
  if (!system?.version) return null;
  const t = await getTranslations("node");

  return (
    <p className="flex items-start gap-2 rounded-lg border bg-muted/30 px-4 py-3 text-sm text-muted-foreground">
      <Info className="mt-0.5 size-4 shrink-0" />
      <span>
        {t("system.note", { version: system.version })}
        {system.path ? <span className="ml-1 font-mono text-xs">{system.path}</span> : null}
      </span>
    </p>
  );
}

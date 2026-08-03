import { useTranslations } from "next-intl";
import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";

/**
 * How long this version is still getting fixes.
 *
 * Shared by PHP and Node, which use different words for the same three states —
 * PHP has `active | security | eol`, Node has `current | lts | maintenance |
 * eol` — so the tone is mapped here and the wording comes from the caller's
 * own namespace.
 *
 * Absent data shows nothing rather than "unknown": a self-hosted box behind a
 * firewall never reaches the upstream schedule, and a badge saying unknown
 * makes a perfectly current version look suspect.
 */
const TONE = {
  active: "success",
  current: "success",
  lts: "success",
  security: "warning",
  maintenance: "warning",
  eol: "destructive",
};

export function LifecycleBadge({ lifecycle, namespace, available = true, className }) {
  const t = useTranslations(`${namespace}.lifecycle`);
  if (!available || !lifecycle?.status) return null;

  const key = lifecycle.status;
  if (!t.has(key)) return null;

  return (
    <Badge variant={TONE[key] ?? "secondary"} className={cn("font-normal", className)}>
      {/* Node names its LTS lines ("Iron", "Jod"), and that name is how people
          refer to them in release notes. Only shown when the API sends one. */}
      {key === "lts" && lifecycle.lts_name
        ? t("ltsNamed", { name: lifecycle.lts_name })
        : t(key)}
    </Badge>
  );
}

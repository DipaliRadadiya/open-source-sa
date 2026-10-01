import { useTranslations } from "next-intl";
import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";

// Shared by PHP and Node: tone is mapped here, wording comes from the caller.
// Absent data shows nothing: firewalled servers never reach the upstream schedule.
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
    <Badge variant={TONE[key] ?? "muted"} className={cn("font-normal", className)}>
      {/* Node's LTS codename ("Iron", "Jod"), when the API sends one. */}
      {key === "lts" && lifecycle.lts_name
        ? t("ltsNamed", { name: lifecycle.lts_name })
        : t(key)}
    </Badge>
  );
}

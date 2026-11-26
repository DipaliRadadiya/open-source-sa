import { useTranslations } from "next-intl";
import { CircleCheck, CircleMinus, CircleAlert, CircleHelp, Loader2 } from "lucide-react";
import { Badge } from "@/components/ui/badge";

const STATUS_META = {
  active: { icon: CircleCheck, variant: "success" },
  inactive: { icon: CircleMinus, variant: "muted" },
  failed: { icon: CircleAlert, variant: "destructive" },
};

export function ServiceStatusBadge({ status, state = "installed", busyAction }) {
  const t = useTranslations("services");

  if (busyAction && t.has(`busy.${busyAction}`)) {
    return (
      <Badge variant="muted">
        <Loader2 className="size-3 animate-spin" />
        {t(`busy.${busyAction}`)}
      </Badge>
    );
  }

  if (state === "installing") {
    return (
      <Badge variant="muted">
        <Loader2 className="size-3 animate-spin" />
        {t("state.installing")}
      </Badge>
    );
  }

  const meta = STATUS_META[status];
  const Icon = meta?.icon ?? CircleHelp;
  return (
    <Badge variant={meta?.variant ?? "muted"}>
      <Icon className="size-3" />
      {t.has(`status.${status}`) ? t(`status.${status}`) : status}
    </Badge>
  );
}

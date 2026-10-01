import { useTranslations } from "next-intl";
import { CircleCheck, CircleMinus, CircleAlert, CircleHelp, Loader2 } from "lucide-react";
import { Badge } from "@/components/ui/badge";

// Icon + word + colour, so failed is not signalled by colour alone.
const STATUS_META = {
  active: { icon: CircleCheck, variant: "success" },
  inactive: { icon: CircleMinus, variant: "muted" },
  failed: { icon: CircleAlert, variant: "destructive" },
};

export function ServiceStatusBadge({ status, state = "installed", busyAction }) {
  const t = useTranslations("services");

  // While an action is in flight, report the transition rather than a stale state.
  if (busyAction && t.has(`busy.${busyAction}`)) {
    return (
      <Badge variant="outline" className="gap-1.5 font-normal text-muted-foreground">
        <Loader2 className="size-3 animate-spin" />
        {t(`busy.${busyAction}`)}
      </Badge>
    );
  }

  // Installing reports `inactive`, but "Stopped" would suggest it can be started.
  // A FAILED install falls through to the `failed` badge.
  if (state === "installing") {
    return (
      <Badge variant="outline" className="gap-1.5 font-normal text-muted-foreground">
        <Loader2 className="size-3 animate-spin" />
        {t("state.installing")}
      </Badge>
    );
  }

  // An unrecognised status is shown verbatim rather than guessed.
  const meta = STATUS_META[status];
  const Icon = meta?.icon ?? CircleHelp;
  return (
    <Badge variant={meta?.variant ?? "outline"} className="gap-1.5 font-normal">
      <Icon className="size-3" />
      {t.has(`status.${status}`) ? t(`status.${status}`) : status}
    </Badge>
  );
}

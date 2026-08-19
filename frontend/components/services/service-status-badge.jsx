"use client";

import { useTranslations } from "next-intl";
import { CircleCheck, CircleMinus, CircleAlert, CircleHelp, Loader2 } from "lucide-react";
import { Badge } from "@/components/ui/badge";

// Icon + word + colour. Colour alone would leave the one state that matters —
// failed — invisible to anyone who can't separate red from green.
const STATUS_META = {
  active: { icon: CircleCheck, variant: "success" },
  inactive: { icon: CircleMinus, variant: "outline" },
  failed: { icon: CircleAlert, variant: "destructive" },
};

/**
 * The status of one service, shared by the desktop table and the mobile cards
 * so the two layouts can't drift into describing the same state differently.
 */
export function ServiceStatusBadge({ status, state = "installed", busyAction }) {
  const t = useTranslations("services");

  // While an action is in flight the old status is no longer true and the new
  // one isn't known yet — so the badge reports the transition rather than a
  // state we can't vouch for.
  if (busyAction && t.has(`busy.${busyAction}`)) {
    return (
      <Badge variant="outline" className="gap-1.5 font-normal text-muted-foreground">
        <Loader2 className="size-3 animate-spin" />
        {t(`busy.${busyAction}`)}
      </Badge>
    );
  }

  // Still installing: there is no unit yet, so the API reports `inactive` —
  // truthfully, but "Stopped" is the wrong word for something mid-install and
  // would read as a thing you could start. Same reasoning as `busyAction`
  // above: report the transition rather than a resting state that misleads.
  //
  // A FAILED install deliberately falls through to the badge below. The API
  // sends `failed` for it because to the person looking at the row it is
  // broken and should read as broken; which kind of broken is said in words
  // next to the name, not by inventing a fourth badge colour.
  if (state === "installing") {
    return (
      <Badge variant="outline" className="gap-1.5 font-normal text-muted-foreground">
        <Loader2 className="size-3 animate-spin" />
        {t("state.installing")}
      </Badge>
    );
  }

  // An unrecognised status is shown verbatim rather than bucketed into one of
  // ours — guessing here would misreport the state of the box.
  const meta = STATUS_META[status];
  const Icon = meta?.icon ?? CircleHelp;
  return (
    <Badge variant={meta?.variant ?? "outline"} className="gap-1.5 font-normal">
      <Icon className="size-3" />
      {t.has(`status.${status}`) ? t(`status.${status}`) : status}
    </Badge>
  );
}

import { ShieldOff } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { CardList, CardListItem } from "@/components/data-table/card-list";
import { BanAge } from "@/components/fail2ban/banned-card";

// Cards for narrow screens, where the table would push Unban off the edge.
export function BannedCards({ data, canManage, onRequestUnban, unbanning, t, renderExpiry }) {
  return (
    <CardList>
      {data.map((ban) => (
        <CardListItem key={`${ban.jail}-${ban.ip}`}>
          <div className="min-w-0 space-y-1.5">
            <p className="break-all font-mono text-sm font-medium">{ban.ip}</p>
            <Badge variant="outline" className="max-w-full break-all whitespace-normal font-normal">
              {ban.jail}
            </Badge>
            {ban.banned_at ? (
              <p className="break-words text-xs text-muted-foreground">
                {t.rich("banned.sinceShort", { at: <BanAge ban={ban} /> })}
              </p>
            ) : null}
          </div>

          <div className="mt-auto flex flex-wrap items-end justify-between gap-3 border-t pt-3">
            <div className="min-w-0 space-y-1 text-sm">
              <p className="text-xs text-muted-foreground">{t("banned.timeLeft")}</p>
              {renderExpiry(ban)}
            </div>
            <ReasonTooltip reason={canManage ? null : t("disabled.noPermission")}>
              <Button
                variant="destructive"
                size="sm"
                disabled={!canManage || unbanning === ban.ip}
                onClick={() => onRequestUnban(ban)}
              >
                <ShieldOff className="size-4" />
                {t("banned.unban")}
              </Button>
            </ReasonTooltip>
          </div>
        </CardListItem>
      ))}
    </CardList>
  );
}

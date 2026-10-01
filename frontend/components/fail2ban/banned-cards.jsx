import { ShieldOff } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { CardList, CardListItem } from "@/components/data-table/card-list";

// Cards for narrow screens, where the table would push Unban off the edge.
export function BannedCards({ data, canManage, onRequestUnban, unbanning, t, renderExpiry }) {
  return (
    <CardList>
      {data.map((ban) => (
        <CardListItem key={`${ban.jail}-${ban.ip}`}>
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0">
              <p className="truncate font-mono text-sm font-medium">{ban.ip}</p>
              <Badge variant="outline" className="mt-1.5 font-normal">
                {ban.jail}
              </Badge>
              {/* Own line: sharing a line truncated the timestamp. */}
              {ban.banned_at ? (
                <p className="mt-1.5 text-xs text-muted-foreground">
                  {t("banned.sinceShort", { at: ban.banned_at })}
                </p>
              ) : null}
            </div>
            <div className="shrink-0 text-right text-sm">{renderExpiry(ban)}</div>
          </div>

          <div className="mt-3 flex justify-end border-t pt-3">
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

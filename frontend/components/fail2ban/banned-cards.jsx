"use client";

import { ShieldOff } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { CardList, CardListItem } from "@/components/data-table/card-list";

/**
 * The same bans as cards, for screens too narrow for five columns.
 *
 * The table scrolls sideways on a phone, which puts Unban off the right edge
 * with nothing to suggest a swipe — the one thing you came to do is the one
 * thing you can't see. Mirrors the Services page, which had the same problem.
 *
 * The columns are also re-ranked for a narrow card: how long is left leads,
 * because on a phone you are deciding whether to wait or to act. The exact
 * ban timestamp is the least urgent thing here, so it goes last and small.
 */
// Unban asks first, same as the table: releasing an address is a security
// decision, and a mis-tap on a phone is a lot easier than a mis-click.
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
              {/* Its own line rather than sharing one with the button: sharing
                  truncated it to "2026-07-29 1…", which is worse than useless —
                  it looks like data while telling you nothing. */}
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

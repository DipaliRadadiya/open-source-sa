"use client";

import { Pencil } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Switch } from "@/components/ui/switch";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { CardList, CardListItem } from "@/components/data-table/card-list";
import {
  RuleName,
  ActionBadge,
  PortText,
  ProtocolText,
  SourceText,
  DeleteRuleButton,
} from "@/components/firewall/rule-parts";

/**
 * The same rules as cards, for screens too narrow for six columns.
 *
 * The table scrolls sideways on a phone and puts delete off the right edge with
 * nothing hinting at a swipe. Here the name leads, the facts sit in one line
 * beneath it, and delete is reachable.
 */
export function RulesCards({
  rules,
  enabled,
  canManage,
  pending,
  onDelete,
  onToggle,
  onRename,
  labels,
}) {
  return (
    <CardList>
      {rules.map((rule) => (
        <CardListItem key={rule.id}>
          <div className="flex items-start justify-between gap-2">
            <div className="min-w-0 space-y-2">
              <RuleName rule={rule} muted={!enabled} labels={labels} />
              <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                <ActionBadge rule={rule} labels={labels} />
                <PortText rule={rule} />
                <ProtocolText rule={rule} labels={labels} />
              </div>
            </div>
            {/* This card only exists on phones, and its controls are the
                compact desktop sizes (a 38x28 icon button). A 44px minimum box
                on each keeps the look and makes them reliably tappable. */}
            <div className="flex shrink-0 items-center gap-1 max-sm:[&_button:not([role=switch])]:min-h-11 max-sm:[&_button:not([role=switch])]:min-w-11">
              <ReasonTooltip reason={canManage ? null : labels.noPermission}>
                <Switch
                  checked={rule.enabled !== false}
                  onCheckedChange={() => onToggle(rule)}
                  disabled={!canManage || pending === rule.id}
                  aria-label={labels.toggle}
                />
              </ReasonTooltip>
              <ReasonTooltip reason={canManage ? null : labels.noPermission}>
                <Button
                  variant="ghost"
                  size="sm"
                  disabled={!canManage || pending === rule.id}
                  onClick={() => onRename(rule)}
                  aria-label={labels.rename}
                >
                  <Pencil className="size-4" />
                </Button>
              </ReasonTooltip>
              <DeleteRuleButton
                rule={rule}
                enabled={enabled}
                canManage={canManage}
                pending={pending === rule.id}
                onDelete={onDelete}
                labels={labels}
              />
            </div>
          </div>

          <p className="mt-3 flex items-center gap-1.5 border-t pt-3 text-xs text-muted-foreground">
            {labels.from} <SourceText rule={rule} labels={labels} />
          </p>
        </CardListItem>
      ))}
    </CardList>
  );
}

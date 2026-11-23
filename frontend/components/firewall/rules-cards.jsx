import { PendingSwitch } from "@/components/ui/pending-switch";
import { ReasonTooltip, readOnlyReason } from "@/components/ui/reason-tooltip";
import { CardList, CardListItem } from "@/components/data-table/card-list";
import {
  RuleName,
  ActionBadge,
  PortProtocol,
  SourceText,
  RuleRowMenu,
  ProtectedTag,
  isLockedOn,
  protectedReasonFor,
} from "@/components/firewall/rule-parts";

// Cards for screens too narrow for six columns, so delete stays reachable.
export function RulesCards({
  rules,
  enabled,
  canManage,
  pending = [],
  onDelete,
  onToggle,
  shownEnabled,
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
                <PortProtocol rule={rule} labels={labels} />
              </div>
            </div>
            {/* Phone-only card with compact desktop-size controls; a 44px minimum box keeps
                them tappable. */}
            <div className="flex shrink-0 items-center gap-1 max-sm:[&_button:not([role=switch]):not([data-slot=protected-tag])]:min-h-11 max-sm:[&_button:not([role=switch]):not([data-slot=protected-tag])]:min-w-11">
              {isLockedOn(rule, shownEnabled(rule)) ? (
                <ProtectedTag labels={labels} />
              ) : (
                <>
                  {/* Same directional guard as the table: a protected rule can always be switched
                      back ON, never OFF. */}
                  <ReasonTooltip
                    reason={readOnlyReason(protectedReasonFor({
                      rule,
                      enabled,
                      canManage,
                      labels,
                      turningOff: shownEnabled(rule),
                    }))}
                  >
                    <PendingSwitch
                      readOnly={!canManage}
                      checked={shownEnabled(rule)}
                      pending={pending.includes(rule.id)}
                      onCheckedChange={() => onToggle(rule)}
                      disabled={Boolean(
                        protectedReasonFor({
                          rule,
                          enabled,
                          canManage,
                          labels,
                          turningOff: shownEnabled(rule),
                        }),
                      )}
                      aria-label={labels.toggle}
                    />
                  </ReasonTooltip>
                </>
              )}
              <RuleRowMenu
                rule={rule}
                enabled={enabled}
                canManage={canManage}
                pending={pending.includes(rule.id)}
                onDelete={onDelete}
                onRename={onRename}
                deletable={!isLockedOn(rule, shownEnabled(rule))}
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

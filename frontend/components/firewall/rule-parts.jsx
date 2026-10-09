import { Lock, Trash2 } from "lucide-react";
import { cn } from "@/lib/utils";
import { protectedReasonFor } from "@/lib/firewall/protected-rule";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";

// Shared by the table and the mobile cards; copy arrives as a `labels` object.

/** Seeded rules have no description, so an unnamed rule borrows its port's service name. */
export function RuleName({ rule, muted, labels }) {
  const off = rule.enabled === false;
  return (
    <div className="flex min-w-0 items-center gap-1.5">
      <span
        className={cn(
          "min-w-0 truncate text-sm font-medium",
          (muted || off) && "text-muted-foreground",
          off && "line-through decoration-muted-foreground/50",
        )}
      >
        {rule.description || labels.nameFor?.(rule) || labels.unnamed}
      </span>
      {off ? (
        <span className="shrink-0 rounded bg-muted px-1.5 py-0.5 text-xs font-medium text-muted-foreground">
          {labels.off}
        </span>
      ) : null}
      {rule.protected ? (
        <Tooltip>
          <TooltipTrigger asChild>
            <span tabIndex={0} className="shrink-0 rounded">
              <Lock className="size-3.5 text-muted-foreground" />
            </span>
          </TooltipTrigger>
          <TooltipContent className="max-w-60">{labels.protectedHint}</TooltipContent>
        </Tooltip>
      ) : null}
    </div>
  );
}

/** Allow and deny never share a colour. */
export function ActionBadge({ rule, labels }) {
  const deny = rule.action === "deny";
  return (
    <Badge variant={deny ? "destructive" : "success"}>
      {deny ? labels.deny : labels.allow}
    </Badge>
  );
}

export function PortText({ rule }) {
  const port = rule.port_to ? `${rule.port_from}–${rule.port_to}` : rule.port_from;
  return <span className="whitespace-nowrap font-mono text-sm">{port ?? "—"}</span>;
}

export function ProtocolText({ rule, labels }) {
  const value = rule.protocol;
  return (
    <span className="text-sm uppercase text-muted-foreground">
      {!value || value === "all" ? labels.anyProtocol : value}
    </span>
  );
}

/** No source means every address, stated in words. */
export function SourceText({ rule, labels }) {
  return (
    <span
      className={cn(
        "truncate text-sm",
        rule.source_ip ? "font-mono" : "text-muted-foreground",
      )}
    >
      {rule.source_ip || labels.anywhere}
    </span>
  );
}

/** Delete, with the reason when a system-seeded rule is protected by the lockout guard. */
export { protectedReasonFor };

export function DeleteRuleButton({ rule, enabled, canManage, pending, onDelete, labels }) {
  // Deleting a seeded rule sets the same trap as switching it off, so it is refused
  // whether or not the firewall is enforcing.
  const reason = protectedReasonFor({ rule, enabled, canManage, labels });
  const lockedByGuard = Boolean(rule.protected);

  return (
    <ReasonTooltip reason={reason}>
      <Button
        variant="ghost"
        size="sm"
        // Red, not grey: grey reads as disabled, and this is the row's destructive action.
        className="text-destructive/80 hover:bg-destructive/10 hover:text-destructive"
        disabled={!canManage || lockedByGuard || pending}
        onClick={() => onDelete(rule)}
        aria-label={labels.delete}
      >
        <Trash2 className="size-4" />
      </Button>
    </ReasonTooltip>
  );
}

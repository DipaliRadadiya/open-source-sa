import { Lock, Trash2 } from "lucide-react";
import { cn } from "@/lib/utils";
import { protectedReasonFor } from "@/lib/firewall/protected-rule";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { Popover, PopoverArrow, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { useHoverPopover } from "@/lib/hooks/use-hover-popover";

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

/** Port and protocol read as one value ("443 / TCP"), so they share a column. */
export function PortProtocol({ rule, labels }) {
  return (
    <span className="inline-flex items-baseline gap-1.5 whitespace-nowrap">
      <PortText rule={rule} />
      <span aria-hidden className="text-muted-foreground/50">/</span>
      <ProtocolText rule={rule} labels={labels} />
    </span>
  );
}

/**
 * In place of the switch and delete on a panel-seeded rule that is on: both are refused,
 * and two faded controls read as broken rather than as kept on purpose.
 */
export function ProtectedTag({ labels }) {
  // A Popover, not a Tooltip: the reason has to open on a phone tap too.
  const { open, onOpenChange, triggerProps, contentProps } = useHoverPopover({ focusOpens: true });
  return (
    <Popover open={open} onOpenChange={onOpenChange}>
      <PopoverTrigger
        type="button"
        data-slot="protected-tag"
        className="inline-flex h-7 shrink-0 items-center gap-1 rounded-md bg-muted px-2 text-xs font-medium text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
        {...triggerProps}
      >
        <Lock className="size-3" aria-hidden />
        {labels.protectedTag}
      </PopoverTrigger>
      <PopoverContent
        className="w-auto max-w-60 gap-0 rounded-md bg-foreground px-3 py-1.5 text-xs text-background shadow-none ring-0"
        {...contentProps}
        onOpenAutoFocus={(event) => event.preventDefault()}
        onCloseAutoFocus={(event) => event.preventDefault()}
      >
        {labels.protectedReason}
        <PopoverArrow className="bg-foreground fill-foreground" />
      </PopoverContent>
    </Popover>
  );
}

/** Seeded and on: the switch and delete would both be refused. */
export function isLockedOn(rule, shownOn) {
  return Boolean(rule.protected) && shownOn;
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

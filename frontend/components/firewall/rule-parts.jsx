"use client";

import { Lock, Trash2 } from "lucide-react";
import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";

/**
 * The pieces a rule is drawn from, shared by the table and the mobile cards.
 *
 * These are columns, not a sentence. The API also sends a ready-made `summary`
 * ("Allow 443/tcp from Anywhere") and the first version used it for the whole
 * row — but a list of sentences can only be read one at a time, while columns
 * can be scanned downwards. Comparing ten rules is the actual job here, so the
 * facts are separated and the sentence is kept for the narrow layout.
 *
 * Copy arrives as a `labels` object: these are presentational and shouldn't own
 * a translation namespace.
 */

/**
 * The rule's name.
 *
 * Seeded rules carry no description, so this used to print "Untitled rule" three
 * times in a row — a column of nothing. The port already says what the rule is
 * for, so an unnamed rule borrows the service name that owns that port and falls
 * back to the port itself. A name is only invented when it would be accurate.
 */
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
        <span className="shrink-0 rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
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

/** Allow and deny are opposite instructions, so they never share a colour. */
export function ActionBadge({ rule, labels }) {
  const deny = rule.action === "deny";
  return (
    <Badge
      variant="outline"
      className={cn(
        "font-normal uppercase",
        deny ? "border-destructive/40 text-destructive" : "border-success/40 text-success",
      )}
    >
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

/** No source means every address, which is a fact worth stating in words. */
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

/**
 * Delete, with the reason it's unavailable when it is.
 *
 * A system-seeded rule can't be removed while the firewall is on — that's the
 * lockout guard, and it's the most confusing disabled button on the page, so it
 * explains itself rather than just being grey.
 */
export function DeleteRuleButton({ rule, enabled, canManage, pending, onDelete, labels }) {
  const lockedByGuard = Boolean(rule.protected) && enabled;
  const reason = !canManage
    ? labels.noPermission
    : lockedByGuard
      ? labels.protectedReason
      : null;

  return (
    <ReasonTooltip reason={reason}>
      <Button
        variant="ghost"
        size="sm"
        // Red, not grey: a grey icon in a row of grey text reads as disabled,
        // and this is the one destructive action on the row.
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

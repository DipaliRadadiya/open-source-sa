import { Lock, MoreHorizontal, Pencil, Trash2 } from "lucide-react";
import { cn } from "@/lib/utils";
import { protectedReasonFor } from "@/lib/firewall/protected-rule";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { MenuItemHint } from "@/components/data-table/menu-item-hint";
import { Popover, PopoverArrow, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { useHoverPopover } from "@/lib/hooks/use-hover-popover";

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

export function PortProtocol({ rule, labels }) {
  return (
    <span className="inline-flex items-baseline gap-1.5 whitespace-nowrap">
      <PortText rule={rule} />
      <span aria-hidden className="text-muted-foreground/50">/</span>
      <ProtocolText rule={rule} labels={labels} />
    </span>
  );
}

export function ProtectedTag({ labels }) {
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

export { protectedReasonFor };

export function RuleRowMenu({ rule, enabled, canManage, pending, onDelete, onRename, deletable = true, labels }) {
  if (!canManage) return null;
  const deleteReason = protectedReasonFor({ rule, enabled, canManage, labels });
  const noDelete = !deletable || !canManage || Boolean(rule.protected) || pending;
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" size="icon" className="size-8" disabled={pending}>
          <MoreHorizontal className="size-4" />
          <span className="sr-only">{labels.actions}</span>
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-48">
        <DropdownMenuItem onSelect={() => onRename(rule)}>
          <Pencil className="size-4" />
          {labels.rename}
        </DropdownMenuItem>
        <DropdownMenuSeparator />
        <MenuItemHint hint={noDelete ? (deleteReason ?? (deletable ? null : labels.protectedReason)) : null}>
          <DropdownMenuItem variant="destructive" disabled={noDelete} onSelect={() => onDelete(rule)}>
            <Trash2 className="size-4" />
            {labels.delete}
          </DropdownMenuItem>
        </MenuItemHint>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

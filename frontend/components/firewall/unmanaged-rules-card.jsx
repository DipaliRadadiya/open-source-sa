"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { MoreHorizontal, ShieldPlus, TerminalSquare, Trash2 } from "lucide-react";
import { adoptUnmanagedRule, deleteUnmanagedRule } from "@/lib/api/firewall";
import { apiMessage } from "@/lib/api/error-message";
import { useRefresh } from "@/hooks/use-refresh";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { MenuItemHint } from "@/components/data-table/menu-item-hint";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";

/**
 * Rules ufw enforces that the panel has no row for — someone ran `ufw allow` on the
 * server. They work, but the list above does not show them, so they get their own
 * card: take one over (the panel manages it from then on) or remove it.
 */
export function UnmanagedRulesCard({ rules, canManage }) {
  const t = useTranslations("firewall.unmanaged");
  const { refreshAndWait } = useRefresh();
  const [busy, setBusy] = useState(null);
  const [confirm, setConfirm] = useState(null);

  if (!rules?.length) return null;

  async function run(key, action, done) {
    setBusy(key);
    try {
      await action();
      await refreshAndWait();
      toast.success(done);
      return true;
    } catch (error) {
      // The server's own words: it names the lockout guard or why it cannot hold a rule.
      toast.error(apiMessage(error, t("failed")));
      return false;
    } finally {
      setBusy(null);
    }
  }

  return (
    <>
      <Card className="gap-0 overflow-hidden py-0">
        <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3.5">
          <div className="min-w-0">
            <h2 className="flex items-center gap-2 text-[15px] font-semibold tracking-tight">
              {t("title")}
              <span className="text-xs font-normal tabular-nums text-muted-foreground">{rules.length}</span>
            </h2>
            <p className="text-sm text-muted-foreground">{t("description")}</p>
          </div>
        </div>
        <ul className="divide-y">
          {rules.map((rule) => {
            const adoptReason = !canManage ? t("noPermission") : !rule.adoptable ? (rule.reason || t("notAdoptable")) : null;
            // The API refuses to remove a rule it cannot hold, too (422 on `key`).
            const removeReason = !canManage ? t("noPermission") : !rule.adoptable ? t("removeOnServer") : null;
            return (
              <li key={rule.key} className="flex items-center gap-3 px-5 py-3">
                <span className="flex size-8 shrink-0 items-center justify-center rounded-lg border bg-muted/40">
                  <TerminalSquare className="size-4 text-muted-foreground" />
                </span>
                <div className="min-w-0 flex-1">
                  <p className="font-mono text-[13px] font-medium break-all">{rule.rule}</p>
                  <p className="text-xs text-muted-foreground">
                    {rule.adoptable ? t("adoptableLine") : (rule.reason || t("notAdoptable"))}
                  </p>
                </div>
                <DropdownMenu>
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <DropdownMenuTrigger asChild>
                        <Button variant="ghost" size="icon" className="size-8" disabled={busy === rule.key} aria-label={t("actions", { rule: rule.rule })}>
                          <MoreHorizontal className="size-4" />
                        </Button>
                      </DropdownMenuTrigger>
                    </TooltipTrigger>
                    <TooltipContent>{t("actions", { rule: rule.rule })}</TooltipContent>
                  </Tooltip>
                  <DropdownMenuContent align="end" className="w-56">
                    <MenuItemHint hint={adoptReason}>
                      <DropdownMenuItem
                        disabled={Boolean(adoptReason)}
                        onSelect={() => run(rule.key, () => adoptUnmanagedRule(rule.key), t("adopted", { rule: rule.rule }))}
                      >
                        <ShieldPlus className="size-4" />
                        {t("adopt")}
                      </DropdownMenuItem>
                    </MenuItemHint>
                    <DropdownMenuSeparator />
                    <MenuItemHint hint={removeReason}>
                      <DropdownMenuItem variant="destructive" disabled={Boolean(removeReason)} onSelect={() => setConfirm(rule)}>
                        <Trash2 className="size-4" />
                        {t("remove")}
                      </DropdownMenuItem>
                    </MenuItemHint>
                  </DropdownMenuContent>
                </DropdownMenu>
              </li>
            );
          })}
        </ul>
      </Card>

      <ConfirmDialog
        open={confirm !== null}
        onOpenChange={(open) => !open && busy === null && setConfirm(null)}
        icon={Trash2}
        tone="destructive"
        title={t("removeTitle")}
        description={confirm ? t("removeBody", { rule: confirm.rule }) : ""}
        confirmLabel={t("remove")}
        confirmVariant="destructive"
        pending={busy !== null}
        onConfirm={async () => {
          if (!confirm) return;
          if (await run(confirm.key, () => deleteUnmanagedRule(confirm.key), t("removed", { rule: confirm.rule }))) setConfirm(null);
        }}
      />
    </>
  );
}

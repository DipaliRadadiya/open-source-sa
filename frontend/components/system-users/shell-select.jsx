import { useState } from "react";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Loader2 } from "lucide-react";
import { cn } from "@/lib/utils";
import { setSystemUserShell } from "@/lib/api/system-users";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { apiMessage } from "@/lib/api/error-message";
import { useRefresh } from "@/hooks/use-refresh";
import { offeredShells } from "@/lib/system-users/offered-shells";

// A login-refusing shell cannot be set while SSH access is on (the server
// rejects the pair), so that option is disabled with the reason.
export function ShellSelect({ user, shells = [], canManage = true, className }) {
  const t = useTranslations("systemUsers");
  const { refresh, refreshThen } = useRefresh();
  const [busy, setBusy] = useState(false);
  // The requested shell until router.refresh() lands, or the trigger snaps back
  // during the usermod round trip.
  const [asked, setAsked] = useState(null);

  const shown = asked !== null && asked !== user.shell ? asked : user.shell;

  // `shell_allows_login: null` is an unrecognised shell ("unknown", not "denies
  // login"); the raw path is shown.
  const current = shells.find((entry) => entry.value === shown);
  const label =
    (shown === user.shell ? user.shell_title : null) ?? current?.title ?? shown;

  if (!canManage) {
    return <span className="text-xs text-muted-foreground">{label}</span>;
  }

  async function onChange(value) {
    setBusy(true);
    setAsked(value);
    try {
      await setSystemUserShell(user.id, value);
      refreshThen(() => {
        toast.success(t("toast.shellChanged"));
        setBusy(false);
      });
    } catch (error) {
      // Revert: the shell did not change.
      setAsked(null);
      setBusy(false);
      if (error?.response?.status === 404) {
        toast.info(t("toast.alreadyGone", { username: user.username }));
        refresh();
        return;
      }
      toast.error(apiMessage(error, t("toast.shellFailed")));
    }
  }

  const options = shells.length
    ? offeredShells(shells, user.shell)
    : // No catalog (the request failed): keep the current value visible so a
      // lost list never reads as a lost setting.
      [{ value: user.shell, title: label, description: "", allows_login: null }];

  return (
    <Select value={shown} disabled={busy} onValueChange={onChange}>
      {/* Sized to the title, not a fixed width, which cut long translations mid-word. */}
      <SelectTrigger aria-label={t("access.shell")} className={cn("h-8 w-auto max-w-72 text-xs", className)}>
        {/* Title only: Radix copies the selected item's children into the trigger, so the
            path and description would add a second line. */}
        <SelectValue>{label}</SelectValue>
        {/* A spinner so the greyed control reads as busy, like PendingSwitch. */}
        {busy ? <Loader2 className="size-3.5 shrink-0 animate-spin text-muted-foreground" aria-hidden /> : null}
      </SelectTrigger>
      <SelectContent>
        {options.map((shell) => {
          const blocked = user.ssh_access && shell.allows_login === false;
          return (
            <ReasonTooltip key={shell.value} reason={blocked ? t("shellNeedsLogin") : null}>
              <SelectItem value={shell.value} disabled={blocked} className="text-xs">
                <span className="flex flex-col">
                  <span>{shell.title}</span>
                  <span className="font-mono text-xs text-muted-foreground">
                    {shell.value}
                  </span>
                </span>
              </SelectItem>
            </ReasonTooltip>
          );
        })}
      </SelectContent>
    </Select>
  );
}

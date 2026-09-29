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
import { genericErrorMessage } from "@/lib/api/generic-error";
import { useRefresh } from "@/hooks/use-refresh";
import { offeredShells } from "@/lib/system-users/offered-shells";

/**
 * Inline login-shell picker.
 *
 * Shows the shell's TITLE, not its path: `/usr/sbin/nologin` says nothing to
 * anyone who has not administered a Linux box, and "No login" says the whole
 * thing. The path is kept in the option's description, where it is available
 * without being the headline.
 *
 * A shell that refuses login cannot be set while SSH access is on — the server
 * rejects that pair, so the option is disabled here with the reason rather
 * than sent and bounced.
 */
export function ShellSelect({ user, shells = [], canManage = true, className }) {
  const t = useTranslations("systemUsers");
  const { refresh, refreshThen } = useRefresh();
  const [busy, setBusy] = useState(false);
  // The shell we asked for, until the server agrees with it. Same reason as the
  // access switches: `user.shell` only changes when router.refresh() lands, so
  // picking "No login" snapped the trigger back to "Bash" and left it there,
  // greyed, for the whole usermod round trip — and then the success toast fired
  // while the control still read "Bash".
  const [asked, setAsked] = useState(null);

  const shown = asked !== null && asked !== user.shell ? asked : user.shell;

  // `shell_allows_login: null` is an unrecognised shell on an adopted server —
  // "we do not know", not "denies login". Falling back to the raw path is the
  // honest rendering; inventing a title for it would not be.
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
      // Put it back: the shell did not change.
      setAsked(null);
      setBusy(false);
      if (error?.response?.status === 404) {
        toast.info(t("toast.alreadyGone", { username: user.username }));
        refresh();
        return;
      }
      toast.error(apiMessage(error, genericErrorMessage()));
    }
  }

  const options = shells.length
    ? offeredShells(shells, user.shell)
    : // No catalog (the request failed) — keep the current value visible so a
      // lost list never reads as a lost setting.
      [{ value: user.shell, title: label, description: "", allows_login: null }];

  return (
    <Select value={shown} disabled={busy} onValueChange={onChange}>
      {/* Sized to the title rather than a fixed w-48, which cut "Accès shell
          complet (bash)" and its Spanish and Russian siblings mid-word. */}
      <SelectTrigger className={cn("h-8 w-auto max-w-72 text-xs", className)}>
        {/* Title only. Radix copies the selected item's children into the
            trigger, so without this the path (and any description) rides along
            and the row grows a second line it does not need. */}
        <SelectValue>{label}</SelectValue>
        {/* A greyed control with no spinner reads as broken rather than busy —
            the same affordance PendingSwitch gives the toggles beside it. */}
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

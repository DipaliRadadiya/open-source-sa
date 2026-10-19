import { useTranslations } from "next-intl";
import { Badge } from "@/components/ui/badge";
import { CardFact, CardFacts, CardList, CardListItem } from "@/components/data-table/card-list";
import { AccessSwitch } from "@/components/system-users/access-switch";
import { ShellSelect } from "@/components/system-users/shell-select";
import { PasswordReveal } from "@/components/system-users/password-reveal";
import { CopyButton } from "@/components/ui/copy-button";
import { AppsCell } from "@/components/system-users/apps-cell";
import { SystemUserRowActions } from "@/components/system-users/system-user-row-actions";
import { BulkFailure } from "@/components/system-users/bulk-failure";
import { Checkbox } from "@/components/ui/checkbox";

// The shell picker takes the full width underneath so shell names fit.
export function SystemUsersCards({
  users,
  shells = [],
  canManage = false,
  prevPage = null,
  sshEnforced = null,
  sshPasswordOff = false,
  canOpenSecurity = false,
  // Null for viewers: no checkboxes.
  selection = null,
  onSelectionChange,
  failures = {},
}) {
  const t = useTranslations("systemUsers");

  return (
    <CardList>
      {users.map((user) => (
        <CardListItem key={user.id}>
          <div className="flex items-start justify-between gap-2">
            {selection ? (
              <Checkbox
                className="mt-0.5"
                checked={Boolean(selection[String(user.id)])}
                onCheckedChange={(value) => onSelectionChange?.({ ...selection, [String(user.id)]: value === true })}
                aria-label={t("bulk.selectRow", { username: user.username })}
              />
            ) : null}
            <div className="min-w-0 flex-1">
              <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                {/* break-all, not truncate: the username is the identifier being read, and a long
                    one would push the row menu off the card. */}
                <span className="min-w-0 font-medium break-all">{user.username}</span>
                {/* Same rule as the table: managers see "Not set" in the Password fact; only
                    viewers need the badge. */}
                {!canManage && !(user.password_known ?? user.password) ? (
                  <Badge variant="warning" className="font-normal">
                    {t("noPassword")}
                  </Badge>
                ) : null}
              </div>
              <p className="flex min-w-0 items-center gap-1 font-mono text-xs text-muted-foreground">
                <span className="min-w-0 truncate">{user.home_path}</span>
                <CopyButton value={user.home_path} label={t("detail.copyPath")} className="size-6" />
              </p>
              <BulkFailure failure={failures[user.id]} />
            </div>
            {/* For viewers too: the menu is how SSH keys are reached. */}
            <div className="-me-2 -mt-1 shrink-0">
              <SystemUserRowActions
                user={user}
                canManage={canManage}
                prevPage={prevPage}
                sshPasswordOff={sshPasswordOff}
                canOpenSecurity={canOpenSecurity}
              />
            </div>
          </div>

          <CardFacts>
            <CardFact label={t("sudo")}>
              <AccessSwitch user={user} field="sudo" canManage={canManage} />
            </CardFact>
            <CardFact label={t("ssh")}>
              <AccessSwitch user={user} field="ssh" canManage={canManage} sshEnforced={sshEnforced} />
            </CardFact>
            <CardFact label={t("columns.applications")}>
              <AppsCell user={user} />
            </CardFact>
            <CardFact label={t("columns.created")} value={user.created_at_human} />
            <CardFact label={t("columns.shell")}>
              <ShellSelect user={user} shells={shells} canManage={canManage} className="w-full" />
            </CardFact>
            {/* Last and full width: the longest value. Managers only; viewers get a redacted
                placeholder, not the password. */}
            {canManage ? (
              <CardFact label={t("columns.password")}>
                {/* CardFact right-aligns values; a credential reads left-to-right. */}
                <PasswordReveal password={user.password} className="text-left" />
              </CardFact>
            ) : null}
          </CardFacts>
        </CardListItem>
      ))}
    </CardList>
  );
}

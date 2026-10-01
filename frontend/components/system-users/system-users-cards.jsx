import { useTranslations } from "next-intl";
import { Badge } from "@/components/ui/badge";
import { CardFact, CardFacts, CardList, CardListItem } from "@/components/data-table/card-list";
import { AccessSwitch } from "@/components/system-users/access-switch";
import { ShellSelect } from "@/components/system-users/shell-select";
import { PasswordReveal } from "@/components/system-users/password-reveal";
import { AppsCell } from "@/components/system-users/apps-cell";
import { SystemUserRowActions } from "@/components/system-users/system-user-row-actions";

// The shell picker takes the full width underneath so shell names fit.
export function SystemUsersCards({ users, shells = [], canManage = false, prevPage = null, sshEnforced = null }) {
  const t = useTranslations("systemUsers");

  return (
    <CardList>
      {users.map((user) => (
        <CardListItem key={user.id}>
          <div className="flex items-start justify-between gap-2">
            <div className="min-w-0">
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
              <p className="truncate font-mono text-xs text-muted-foreground">{user.home_path}</p>
            </div>
            {/* For viewers too: the menu is how SSH keys are reached. */}
            <div className="-me-2 -mt-1 shrink-0">
              <SystemUserRowActions user={user} canManage={canManage} prevPage={prevPage} />
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

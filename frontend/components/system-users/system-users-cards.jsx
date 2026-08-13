"use client";

import { useTranslations } from "next-intl";
import { Badge } from "@/components/ui/badge";
import { CardFact, CardFacts, CardList, CardListItem } from "@/components/data-table/card-list";
import { AccessSwitch } from "@/components/system-users/access-switch";
import { ShellSelect } from "@/components/system-users/shell-select";
import { AppsCell } from "@/components/system-users/apps-cell";
import { SystemUserRowActions } from "@/components/system-users/system-user-row-actions";

/**
 * System users on a narrow screen.
 *
 * Eight columns, of which three are live controls — the table put the sudo and
 * SSH switches off the right edge, so on a phone you could read who existed but
 * not what they were allowed to do.
 *
 * Sudo and SSH lead the facts because they are why anyone opens this page. The
 * shell picker takes the full width underneath: it is a control, not a reading,
 * and a 120px select shows half a shell name.
 */
export function SystemUsersCards({ users, shells = [], canManage = false }) {
  const t = useTranslations("systemUsers");

  return (
    <CardList>
      {users.map((user) => (
        <CardListItem key={user.id}>
          <div className="flex items-start justify-between gap-2">
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                <span className="font-medium">{user.username}</span>
                {!user.password ? (
                  <Badge variant="warning" className="font-normal">
                    {t("noPassword")}
                  </Badge>
                ) : null}
              </div>
              <p className="truncate font-mono text-xs text-muted-foreground">{user.home_path}</p>
            </div>
            {canManage ? (
              <div className="-me-2 -mt-1 shrink-0">
                <SystemUserRowActions user={user} />
              </div>
            ) : null}
          </div>

          <CardFacts>
            <CardFact label={t("sudo")}>
              <AccessSwitch user={user} field="sudo" canManage={canManage} />
            </CardFact>
            <CardFact label={t("ssh")}>
              <AccessSwitch user={user} field="ssh" canManage={canManage} />
            </CardFact>
            <CardFact label={t("columns.applications")}>
              <AppsCell user={user} />
            </CardFact>
            <CardFact label={t("columns.created")} value={user.created_at_human} />
            <CardFact label={t("columns.shell")}>
              <ShellSelect user={user} shells={shells} canManage={canManage} className="w-full" />
            </CardFact>
          </CardFacts>
        </CardListItem>
      ))}
    </CardList>
  );
}

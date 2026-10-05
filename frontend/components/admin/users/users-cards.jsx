import { roleName } from "@/lib/roles/role-label";
import { useTranslations } from "next-intl";
import { Badge } from "@/components/ui/badge";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { CardFact, CardFacts, CardList, CardListItem } from "@/components/data-table/card-list";
import { UserRowActions } from "@/components/admin/users/user-row-actions";
import { initials } from "@/lib/format/initials";

// Roles are not capped here: a card wraps, so "+2" would only hide names.
export function UsersCards({ users, roles = [], currentUserId }) {
  const t = useTranslations("users");
  const tr = useTranslations("roles");

  return (
    <CardList>
      {users.map((user) => {
        const userRoles = user.roles ?? [];
        return (
          <CardListItem key={user.id}>
            <div className="flex items-start justify-between gap-2">
              <div className="flex min-w-0 items-start gap-2.5">
                <Avatar className="mt-0.5 size-7 shrink-0">
                  <AvatarFallback className="text-xs">{initials(user.name)}</AvatarFallback>
                </Avatar>
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <span className="min-w-0 font-medium break-all">{user.name}</span>
                    {user.id === currentUserId ? (
                      <Badge variant="secondary" className="font-normal">
                        {t("you")}
                      </Badge>
                    ) : null}
                  </div>
                  <p className="truncate text-xs text-muted-foreground">@{user.username}</p>
                </div>
              </div>
              <div className="-me-2 -mt-1 shrink-0">
                <UserRowActions user={user} roles={roles} currentUserId={currentUserId} />
              </div>
            </div>

            <CardFacts>
              <CardFact label={t("columns.accountType")}>
                <Badge variant={user.is_admin ? "default" : "muted"}>
                  {user.is_admin ? t("roleBadge.admin") : t("roleBadge.user")}
                </Badge>
              </CardFact>
              <CardFact label={t("columns.roles")}>
                {userRoles.length ? (
                  <div className="flex flex-wrap justify-end gap-1">
                    {userRoles.map((role) => (
                      <Badge key={role.id} variant="outline" className="font-normal">
                        {roleName(role, tr)}
                      </Badge>
                    ))}
                  </div>
                ) : (
                  <span className="text-muted-foreground">—</span>
                )}
              </CardFact>
              <CardFact label={t("columns.joined")} value={user.created_at_human} />
            </CardFacts>
          </CardListItem>
        );
      })}
    </CardList>
  );
}

"use client";

import { useTranslations } from "next-intl";
import { Badge } from "@/components/ui/badge";
import { CardFact, CardFacts, CardList, CardListItem } from "@/components/data-table/card-list";
import { RoleRowActions } from "@/components/admin/roles/role-row-actions";
import { grantedCount } from "@/lib/roles/granted-count";

/**
 * Roles on a narrow screen.
 *
 * The table showed the name and part of the description; the permission count,
 * the created date and the row menu were off the right edge — so on a phone you
 * could see that a role existed but not edit or delete it.
 */
export function RolesCards({ roles }) {
  const t = useTranslations("roles");

  return (
    <CardList>
      {roles.map((role) => (
        <CardListItem key={role.id}>
          <div className="flex items-start justify-between gap-2">
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                <span className="min-w-0 font-medium break-all">{role.name}</span>
                {role.is_system ? (
                  <Badge variant="warning" className="font-normal">
                    {t("system")}
                  </Badge>
                ) : null}
              </div>
              {/* Two lines, not one: a card has the room, and a description cut
                  after four words is no more use than none. */}
              <p className="line-clamp-2 text-xs text-muted-foreground">
                {role.description || "—"}
              </p>
            </div>
            <div className="-me-2 -mt-1 shrink-0">
              <RoleRowActions role={role} />
            </div>
          </div>

          <CardFacts>
            <CardFact label={t("columns.permissions")}>
              <Badge variant="secondary">
                {t("permissionCount", { count: grantedCount(role) })}
              </Badge>
            </CardFact>
            <CardFact label={t("columns.created")} value={role.created_at_human} />
          </CardFacts>
        </CardListItem>
      ))}
    </CardList>
  );
}

import { useTranslations } from "next-intl";
import { Plus } from "lucide-react";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { FacetSelect } from "@/components/data-table/facet-select";
import { RefreshButton } from "@/components/data-table/refresh-button";

/**
 * Filters are URL-driven and applied server-side. There's no search box: the
 * cron list endpoint exposes only system_user_id / username / active, and a
 * client-side search over a paginated page would only ever match the rows
 * already on screen.
 */
export function CronjobsToolbar({
  systemUsers = [],
  cronjobs = [],
  canManage = false,
  onCreate,
}) {
  const t = useTranslations("cronJobs");

  // Jobs can run as accounts the panel doesn't manage (root, www-data). Those
  // filter by username instead of id, so surface the ones actually in use.
  // Limitation: only usernames on the current page — there's no endpoint
  // listing every distinct cron username.
  const unmanaged = [
    ...new Set(cronjobs.filter((j) => !j.system_user).map((j) => j.username)),
  ].sort();

  const userOptions = [
    ...systemUsers.map((u) => ({ value: String(u.id), label: u.username })),
    ...unmanaged.map((name) => ({
      value: name,
      label: `${name} · ${t("unmanaged")}`,
    })),
  ];

  return (
    <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
        <FacetSelect
          paramKey="user"
          allLabel={t("filter.allUsers")}
          options={userOptions}
          className="w-full sm:w-52"
        />
        <FacetSelect
          paramKey="active"
          allLabel={t("filter.allStatuses")}
          options={[
            { value: "true", label: t("filter.active") },
            { value: "false", label: t("filter.paused") },
          ]}
          className="w-full sm:w-36"
        />
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <RefreshButton />
        <ReasonTooltip reason={canManage ? null : t("noPermission")}>
          <Button disabled={!canManage} onClick={onCreate}>
            <Plus className="size-4" />
            {t("addJob")}
          </Button>
        </ReasonTooltip>
      </div>
    </div>
  );
}

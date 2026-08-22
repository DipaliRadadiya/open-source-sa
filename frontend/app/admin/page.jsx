import { getTranslations } from "next-intl/server";
import { ArrowUpCircle, Bug, PlugZap, Stethoscope } from "lucide-react";
import { getDashboardStats } from "@/lib/dashboard/get-dashboard";
import { getDoctor } from "@/lib/admin/get-doctor";
import { getPanelUpdate } from "@/lib/admin/get-panel-update";
import { getCentralStatus } from "@/lib/admin/get-central";
import { getErrorLogs } from "@/lib/admin/get-error-logs";
import { getActivityLog } from "@/lib/activity/get-activity-log";
import { getImpersonation } from "@/lib/activity/get-impersonation";
import { groupErrorLogs } from "@/lib/admin/group-error-logs";
import { PageHeader } from "@/components/ui/page-header";
import { StatusTile } from "@/components/admin/dashboard/status-tile";
import { AttentionList } from "@/components/admin/dashboard/attention-list";
import { ActivityFeed } from "@/components/admin/dashboard/activity-feed";
import { PeopleCard } from "@/components/admin/dashboard/people-card";
import { QuickActions } from "@/components/admin/dashboard/quick-actions";

export const dynamic = "force-dynamic";

// The window the error tile counts over. Named in the hint, because "3
// problems" over an unstated period is a number you cannot act on.
const ERROR_WINDOW = 100;

export default async function AdminDashboardPage() {
  const [t, stats, doctor, panelUpdate, central, errors, activity, impersonation] =
    await Promise.all([
      getTranslations("admin"),
      getDashboardStats(),
      getDoctor(),
      getPanelUpdate(),
      getCentralStatus(),
      getErrorLogs(ERROR_WINDOW),
      // The largest page the endpoint offers, and the same single request
      // either way. Anything other than a login is rare enough that a shorter
      // window collapses to one row of "logged in" and shows nothing else.
      getActivityLog({ per_page: 100 }),
      getImpersonation(),
    ]);

  // Every tile has a "we could not ask" state. A dashboard that renders a
  // healthy-looking tile over a failed read is worse than one that says so.
  const errorGroups = errors.failed ? [] : groupErrorLogs(errors.data?.error_logs ?? []);
  const centralOn = central.failed ? null : Boolean(central.data?.central?.enabled);

  const health = (() => {
    if (!doctor) return { tone: "idle", value: t("unknown"), hint: t("tiles.healthUnknown") };
    const hint = t("tiles.healthPassed", { count: doctor.passed });
    if (doctor.failed > 0) {
      return {
        tone: "attention",
        value: t("tiles.healthSummary", { failed: doctor.failed, warnings: doctor.warnings }),
        hint,
      };
    }
    if (doctor.warnings > 0) {
      return { tone: "warning", value: t("tiles.healthWarnings", { count: doctor.warnings }), hint };
    }
    return { tone: "good", value: t("tiles.healthOk"), hint };
  })();

  const version = (() => {
    if (!panelUpdate) return { tone: "idle", value: t("unknown"), hint: t("tiles.versionUnknown") };
    const installed = panelUpdate.installed.version;
    if (panelUpdate.update_available) {
      // An update you cannot install is not the same news as one you can, and
      // the tile said the same thing for both. The count comes from the same
      // response — nothing extra is fetched to say this.
      const blocking = panelUpdate.preflight.checks.filter((c) => !c.passed).length;
      return {
        tone: panelUpdate.preflight.ready ? "action" : "warning",
        value: t("tiles.versionAvailable", { version: panelUpdate.available.version }),
        // Always says which version you are on: "v1.0.2 available" alone
        // leaves you working out whether that is one release ahead or six.
        hint: panelUpdate.preflight.ready
          ? t("tiles.versionCurrent", { version: installed ?? "?" })
          : t("tiles.versionBlocked", { version: installed ?? "?", count: blocking }),
      };
    }
    return {
      tone: "good",
      value: installed ? t("tiles.versionValue", { version: installed }) : t("unknown"),
      hint: panelUpdate.available.checked ? t("tiles.versionUpToDate") : t("tiles.versionUnchecked"),
    };
  })();

  const errorTile = (() => {
    if (errors.failed) return { tone: "idle", value: t("unknown"), hint: t("tiles.errorsUnknown") };
    if (!errorGroups.length) {
      return { tone: "good", value: t("tiles.errorsNone"), hint: t("tiles.errorsWindow", { count: ERROR_WINDOW }) };
    }
    return {
      tone: "warning",
      value: t("tiles.errorsFound", { count: errorGroups.length }),
      hint: t("tiles.errorsWindow", { count: ERROR_WINDOW }),
    };
  })();

  const centralTile =
    centralOn === null
      ? { tone: "idle", value: t("unknown"), hint: t("tiles.centralUnknown") }
      : centralOn
        ? { tone: "good", value: t("tiles.centralOn"), hint: t("tiles.centralOnHint") }
        : { tone: "idle", value: t("tiles.centralOff"), hint: t("tiles.centralOffHint") };

  // Ordering happens inside the list, which interleaves these with the recorded
  // failures; here they are just the ones that are not passing.
  const attentionChecks = doctor ? doctor.checks.filter((c) => c.status !== "pass") : [];

  return (
    <div className="space-y-6">
      <PageHeader title={t("dashboardTitle")} subtitle={t("dashboardSubtitle")} />

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatusTile icon={Stethoscope} title={t("tiles.health")} href="/admin/doctor" {...health} />
        <StatusTile
          icon={ArrowUpCircle}
          title={t("tiles.version")}
          href="/admin/panel-update"
          {...version}
        />
        <StatusTile icon={Bug} title={t("tiles.errors")} href="/admin/error-logs" {...errorTile} />
        <StatusTile icon={PlugZap} title={t("tiles.central")} href="/admin/central" {...centralTile} />
      </div>

      <AttentionList checks={attentionChecks} errorGroups={errorGroups} />

      <QuickActions />

      {/* Weighted but equal height: the feed is the wider of the two because
          its rows are sentences, and both stretch to the taller so the row
          ends on one line. Each card pins its own footer link to the bottom,
          so the slack falls between the list and the link rather than leaving
          a card that stops halfway up its neighbour. */}
      <div className="grid gap-4 lg:grid-cols-3">
        <div className="lg:col-span-2">
          <ActivityFeed
            entries={activity.activity_log}
            todayCount={stats?.activity.today ?? 0}
          />
        </div>
        <PeopleCard users={stats?.users} roles={stats?.roles} impersonation={impersonation} />
      </div>

    </div>
  );
}

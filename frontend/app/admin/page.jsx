import { getDashboardStats } from "@/lib/dashboard/get-dashboard";
import { getDoctor } from "@/lib/admin/get-doctor";
import { getPanelUpdate } from "@/lib/admin/get-panel-update";
import { getCentralStatus } from "@/lib/admin/get-central";
import { getErrorLogs } from "@/lib/admin/get-error-logs";
import { getActivityLog } from "@/lib/activity-log/get-activity-log";
import { getImpersonation } from "@/lib/activity-log/get-impersonation";
import { groupErrorLogs } from "@/lib/admin/group-error-logs";
import { AdminHero } from "@/components/admin/dashboard/admin-hero";
import { centralState } from "@/lib/admin/dashboard-facts";
import { getUsers } from "@/lib/users/get-users";
import { getCurrentUser } from "@/lib/auth/get-current-user";
import { AttentionList } from "@/components/admin/dashboard/attention-list";
import { ActivityFeed } from "@/components/admin/dashboard/activity-feed";
import { AdminSummary } from "@/components/admin/dashboard/admin-summary";
import { failedSignIns, neverSignedIn } from "@/lib/admin/sign-in-facts";

export const dynamic = "force-dynamic";

// How many recent error records the attention row counts over.
const ERROR_WINDOW = 100;
// The largest page the activity endpoint offers.
const PAGE = 100;

export default async function AdminDashboardPage() {
  const [stats, doctorResult, updateResult, central, errors, activity, impersonation, users, me, failedKnown, failedUnknown] =
    await Promise.all([
      getDashboardStats(),
      getDoctor(),
      getPanelUpdate(),
      getCentralStatus(),
      getErrorLogs(ERROR_WINDOW),
      // Largest page the endpoint offers; a shorter window is usually all logins.
      getActivityLog({ per_page: 100 }),
      getImpersonation(),
      // One read for the People card's role counts (the API maximum).
      getUsers({ per_page: 100 }),
      getCurrentUser(),
      // Failed sign-ins, on a known account and on a name that matches none.
      getActivityLog({ type: "user", action: "login_failed", per_page: PAGE }),
      getActivityLog({ type: "user", action: "login_failed_unknown", per_page: PAGE }),
    ]);

  const doctor = doctorResult.doctor;
  const panelUpdate = updateResult.state;

  // Every tile has a failed-read state; never show a healthy tile over a failed read.
  const errorGroups = errors.failed ? [] : groupErrorLogs(errors.data?.error_logs ?? []);
  const centralStatus = central.failed ? null : centralState(central.data?.central ?? { enabled: false });

  const signIns =
    failedKnown.failed || failedUnknown.failed
      ? null
      : failedSignIns([...failedKnown.activity_log, ...failedUnknown.activity_log], {
          pageSize: PAGE,
          reads: [failedKnown.activity_log, failedUnknown.activity_log],
        });
  // The whole list of people, or nothing: a partial one would misname the Central account.
  const people = !users.failed && users.meta.total <= users.users.length ? users.users : null;
  const never = people ? neverSignedIn(people) : null;

  // Ordering happens inside the list, which interleaves these with recorded failures.
  const attentionChecks = doctor ? doctor.checks.filter((c) => c.status !== "pass") : [];

  return (
    <div className="space-y-6">
      {/* The banner is the h1 and carries what the four tiles said (health, version,
          Central); the failures are the rows right under it, so nothing is said twice. */}
      <AdminHero userName={me?.name} doctor={doctor} update={panelUpdate} central={centralStatus} />

      <AttentionList checks={attentionChecks} errorGroups={errorGroups} update={panelUpdate} signIns={signIns} />

      <AdminSummary signIns={signIns} users={stats?.users} never={never} impersonation={impersonation} />

      <ActivityFeed
        entries={activity.activity_log}
        failed={activity.failed}
        todayCount={stats?.activity.today ?? 0}
        todayKnown={Boolean(stats)}
        people={people}
      />
    </div>
  );
}

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
import { PeopleCard } from "@/components/admin/dashboard/people-card";

export const dynamic = "force-dynamic";

// How many recent error records the attention row counts over.
const ERROR_WINDOW = 100;

export default async function AdminDashboardPage() {
  const [stats, doctorResult, updateResult, central, errors, activity, impersonation, users, me] =
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
    ]);

  const doctor = doctorResult.doctor;
  const panelUpdate = updateResult.state;

  // Every tile has a failed-read state; never show a healthy tile over a failed read.
  const errorGroups = errors.failed ? [] : groupErrorLogs(errors.data?.error_logs ?? []);
  const centralStatus = central.failed ? null : centralState(central.data?.central ?? { enabled: false });

  // Ordering happens inside the list, which interleaves these with recorded failures.
  const attentionChecks = doctor ? doctor.checks.filter((c) => c.status !== "pass") : [];

  return (
    <div className="space-y-6">
      {/* The banner is the h1 and carries what the four tiles said (health, version,
          Central); the failures are the rows right under it, so nothing is said twice. */}
      <AdminHero userName={me?.name} doctor={doctor} update={panelUpdate} central={centralStatus} />

      <AttentionList checks={attentionChecks} errorGroups={errorGroups} update={panelUpdate} />

      <div className="grid gap-4 lg:grid-cols-3">
        <div className="lg:col-span-2">
          <ActivityFeed
            entries={activity.activity_log}
            failed={activity.failed}
            todayCount={stats?.activity.today ?? 0}
            todayKnown={Boolean(stats)}
          />
        </div>
        <PeopleCard
          users={stats?.users}
          roles={stats?.roles}
          impersonation={impersonation}
          userList={users.failed ? null : users.users}
        />
      </div>
    </div>
  );
}

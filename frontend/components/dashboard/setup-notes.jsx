import { SetupBanner } from "@/components/setup/setup-banner";
import { SystemUpdatesNote } from "@/components/setup/system-updates-note";

// Streamed in after the rest of the dashboard: `/setup` takes about a second. `read` is the
// page's getSetup() promise, started beside its other reads.
export async function DashboardSetupNotes({ read }) {
  const { setup } = await read;
  // Only while the recommended set is incomplete.
  const left = setup && !setup.complete
    ? setup.components.filter((c) => c.recommended && c.state !== "installed")
    : [];

  return (
    <>
      <SetupBanner remaining={left.length} names={left.map((c) => c.title)} />
      <SystemUpdatesNote running={setup?.system_updates_running} />
    </>
  );
}

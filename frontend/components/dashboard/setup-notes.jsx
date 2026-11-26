import { SetupBanner } from "@/components/setup/setup-banner";
import { SystemUpdatesNote } from "@/components/setup/system-updates-note";

export async function DashboardSetupNotes({ read }) {
  const { setup } = await read;
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

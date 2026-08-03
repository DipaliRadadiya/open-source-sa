import { NotFoundContent } from "@/components/sections/not-found-content";
import { getQuickLinks } from "@/lib/navigation/get-quick-links";

// For notFound() thrown inside the panel — an application or role id that
// doesn't exist. The shell stays, so the user is one click from anywhere; the
// link column still earns its place by naming the likely destinations.
export default async function AppNotFound() {
  const links = await getQuickLinks().catch(() => []);

  return (
    <div className="flex min-h-[60svh] items-center py-8">
      <NotFoundContent links={links} />
    </div>
  );
}

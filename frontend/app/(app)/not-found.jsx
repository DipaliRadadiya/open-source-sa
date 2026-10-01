import { NotFoundContent } from "@/components/sections/not-found-content";
import { getQuickLinks } from "@/lib/navigation/get-quick-links";

// notFound() inside the panel (unknown application or role id); the shell stays.
export default async function AppNotFound() {
  const links = await getQuickLinks().catch(() => []);

  return (
    <div className="flex min-h-[60svh] items-center py-8">
      <NotFoundContent links={links} />
    </div>
  );
}

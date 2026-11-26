import { NotFoundContent } from "@/components/sections/not-found-content";
import { getQuickLinks } from "@/lib/navigation/get-quick-links";

export default async function AppNotFound() {
  const links = await getQuickLinks().catch(() => []);

  return (
    <div className="flex min-h-[60svh] items-center py-8">
      <NotFoundContent links={links} />
    </div>
  );
}

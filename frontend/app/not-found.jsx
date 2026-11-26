import { getTranslations } from "next-intl/server";
import { NotFoundContent } from "@/components/sections/not-found-content";
import { getQuickLinks } from "@/lib/navigation/get-quick-links";

export async function generateMetadata() {
  const t = await getTranslations("errors.notFound");
  return { title: t("title") };
}

export default async function NotFound() {
  const links = await getQuickLinks().catch(() => []);

  return (
    <div className="flex min-h-svh items-center justify-center px-6 py-16">
      <NotFoundContent links={links} />
    </div>
  );
}

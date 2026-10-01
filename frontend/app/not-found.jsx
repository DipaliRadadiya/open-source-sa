import { getTranslations } from "next-intl/server";
import { NotFoundContent } from "@/components/sections/not-found-content";
import { getQuickLinks } from "@/lib/navigation/get-quick-links";

// Next resolves unmatched URLs against the root not-found, outside the panel
// layout, so this page carries its own destinations. notFound() under (app)
// gets the in-shell version instead.
export async function generateMetadata() {
  const t = await getTranslations("errors.notFound");
  return { title: t("title") };
}

export default async function NotFound() {
  // Signed out, this is an empty list and the page renders message-only.
  const links = await getQuickLinks().catch(() => []);

  return (
    <div className="flex min-h-svh items-center justify-center px-6 py-16">
      <NotFoundContent links={links} />
    </div>
  );
}

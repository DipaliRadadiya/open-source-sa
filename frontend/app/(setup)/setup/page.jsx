import { getTranslations, getLocale } from "next-intl/server";
import { Rocket } from "lucide-react";
import { getSetup } from "@/lib/setup/get-setup";
import { getPhp } from "@/lib/php/get-php";
import { getNode } from "@/lib/node/get-node";
import { SetupChecklist } from "@/components/setup/setup-checklist";
import { LoadFailed } from "@/components/data-table/load-failed";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("setup");
  return { title: t("title") };
}

// A component still needing a runtime installed → we'll want its version list
// for the inline picker.
function needsVersions(setup, key) {
  return setup?.components?.some((c) => c.key === key && c.state !== "installed" && c.state !== "installing");
}

export default async function SetupPage() {
  const [t, locale, result] = await Promise.all([getTranslations("setup"), getLocale(), getSetup()]);

  // Fetch installable versions only for the runtimes that still need one, so the
  // PHP/Node cards can install a version inline instead of navigating away.
  const [php, node] = await Promise.all([
    needsVersions(result.setup, "php") ? getPhp() : Promise.resolve({ data: null }),
    needsVersions(result.setup, "node") ? getNode() : Promise.resolve({ data: null }),
  ]);
  const versions = {
    php: php.data?.installable ?? [],
    node: node.data?.installable ?? [],
  };

  return (
    <div className="space-y-6">
      <div className="flex items-start gap-4">
        <span className="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-primary/10 text-primary">
          <Rocket className="size-6" aria-hidden />
        </span>
        <div className="space-y-1.5 pt-0.5">
          <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
          <p className="text-sm leading-6 text-muted-foreground">{t("subtitle")}</p>
        </div>
      </div>

      {/* key by locale: the checklist seeds server data into useState once at
          mount, so switching language must re-mount it to adopt the newly
          localized payload (the frontend strings update via context anyway). */}
      {result.failed || !result.setup ? (
        <LoadFailed description={t("loadFailed")} />
      ) : (
        <SetupChecklist key={locale} initialSetup={result.setup} versions={versions} />
      )}
    </div>
  );
}

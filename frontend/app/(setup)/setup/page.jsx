import { getTranslations, getLocale } from "next-intl/server";
import { Rocket } from "lucide-react";
import { getSetup } from "@/lib/setup/get-setup";
import { getFail2ban } from "@/lib/fail2ban/get-fail2ban";
import { getPhp } from "@/lib/php/get-php";
import { getNode } from "@/lib/node/get-node";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { SetupChecklist } from "@/components/setup/setup-checklist";
import { LoadFailed } from "@/components/data-table/load-failed";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("setup");
  return { title: t("title") };
}

// A component still needing a runtime installed needs its version list for the inline picker.
function needsVersions(setup, key) {
  return setup?.components?.some((c) => c.key === key && c.state !== "installed" && c.state !== "installing");
}

export default async function SetupPage() {
  const [t, locale, result, permissions, fail2ban] = await Promise.all([
    getTranslations("setup"),
    getLocale(),
    getSetup(),
    getPermissions(),
    getFail2ban(),
  ]);
  // Installing fail2ban enables no jail, so jail state is checked too.
  // "unknown" when unreadable, so no false prompt is shown.
  const fail2banProtection = fail2ban.failed
    ? "unknown"
    : fail2ban.data?.installed && (fail2ban.data.jails ?? []).some((jail) => jail.enabled)
      ? "on"
      : "off";
  // Each install is gated by its own feature's manage permission, not `setting` view.
  const canInstall = {
    database: can(permissions, "database", "manage"),
    fail2ban: can(permissions, "fail2ban", "manage"),
    php: can(permissions, "php", "manage"),
    node: can(permissions, "node", "manage"),
    build_tools: can(permissions, "node", "manage"),
    // POST /wp-cli/install is application manage; a missing key reads as allowed.
    wp_cli: can(permissions, "application", "manage"),
  };

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

      {/* Keyed by locale: the checklist seeds useState from the localised
          payload once, so a language switch must remount it. */}
      {result.failed || !result.setup ? (
        <LoadFailed
          description={t("loadFailed")}
          status={result.status}
          failure={result.failure} message={result.message} debug={result.debug}
        />
      ) : (
        <SetupChecklist key={locale} initialSetup={result.setup} versions={versions} canInstall={canInstall} fail2banProtection={fail2banProtection} />
      )}
    </div>
  );
}

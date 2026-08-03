import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getSettings } from "@/lib/settings/get-settings";
import { getFirewall } from "@/lib/firewall/get-firewall";
import { SshForm } from "@/components/settings/ssh-form";
import { changedFor } from "@/lib/settings/changed-for";
import { LoadFailed } from "@/components/data-table/load-failed";

export const dynamic = "force-dynamic";

export default async function SettingsSecurityPage() {
  const [permissions, t, { data, lastChanged, failed }] = await Promise.all([
    getPermissions(),
    getTranslations("settings"),
    getSettings(),
  ]);

  const canManage = can(permissions, "setting", "manage");

  if (failed || !data?.security)
    return <LoadFailed description={t("loadFailed")} />;

  // The rule holding the CURRENT SSH port open. Move the port and it is left
  // guarding a port nothing listens on, so the confirm dialog offers to switch
  // it off. Only looked up when the user can see the firewall; without that
  // permission the dialog says nothing rather than guessing.

  const firewall = can(permissions, "firewall", "view")
    ? await getFirewall()
    : null;
  // Deliberately NOT gated on the firewall being switched on right now: the
  // rule survives, and starts being enforced the moment someone enables the
  // firewall — at which point the old port opens again on its own.
  const oldPortRule = firewall?.data?.rules.find(
    (rule) =>
      rule.port_from === data?.security?.port &&
      !rule.port_to &&
      rule.enabled !== false &&
      rule.action !== "deny",
  );

  return (
    <SshForm
      security={data.security}
      canManage={canManage}
      oldPortRule={
        oldPortRule ? { id: oldPortRule.id, port: oldPortRule.port_from } : null
      }
      canManageFirewall={can(permissions, "firewall", "manage")}
      changedBy={await changedFor(lastChanged, "security")}
    />
  );
}

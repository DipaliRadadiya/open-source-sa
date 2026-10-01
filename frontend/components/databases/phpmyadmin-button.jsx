import { useState } from "react";
import { useTranslations } from "next-intl";
import {
  DisabledReasonProvider,
  ReasonTooltip,
} from "@/components/ui/reason-tooltip";
import { toast } from "sonner";
import Link from "@/components/ui/app-link";
import { ChevronDown, Download, Loader2, TableProperties } from "lucide-react";
import { phpmyadminSso } from "@/lib/api/databases";
import { phpmyadminState, userCount } from "@/lib/databases/phpmyadmin-state";
import { openUrlInNewTab } from "@/lib/browser/new-tab";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";

/**
 * Open this database in phpMyAdmin, already logged in.
 *
 * The returned URL's token lives 60 seconds and is single-use, so the browser
 * is sent straight there (see lib/browser/new-tab.js), never rendered as a link.
 *
 * Hidden for engines phpMyAdmin cannot speak (MongoDB, PostgreSQL). The test is
 * the DRIVER, matching the endpoint's own guard, so new engines need no change.
 */
export function PhpmyadminButton({
  database,
  canManage,
  compact = false,
  /*
   * Every active phpMyAdmin site on this server, or null when the lookup failed
   * (NOT "none", and must not change what the button offers). A list, so the
   * button can offer a choice before the click.
   */
  sites = null,
}) {
  const t = useTranslations("databases.phpmyadmin");
  const [opening, setOpening] = useState(false);
  const installed = sites === null ? null : sites.length > 0;

  /*
   * The refusals knowable in advance, from the SSO endpoint's guards: no active
   * phpMyAdmin site, and no user to sign in as. Others surface as the toast.
   * The pure decision lives in lib/databases/phpmyadmin-state.js.
   */
  const state = phpmyadminState({
    engine: database.engine,
    driver: database.driver,
    installed,
    users: userCount(database),
  });

  // Signing in writes as the database's own user, so the API requires
  // `database` manage. A view-only role gets no button.
  if (state === "hidden" || !canManage) return null;

  // Nothing to open: link to the create-application flow with the type chosen,
  // so the domain and confirmation stay the user's.
  if (state === "install") {
    return (
      <Button asChild variant="outline" size="sm">
        <Link href="/applications/create?type=phpmyadmin">
          <Download className="size-4" />
          {t("install")}
        </Link>
      </Button>
    );
  }

  // phpMyAdmin authenticates as a database user; without one there is no login.
  if (state === "needs-user") {
    return (
      <ReasonTooltip reason={t("needsUser")}>
        <Button type="button" variant="outline" size="sm" disabled>
          <TableProperties className="size-4" />
          {compact ? "phpMyAdmin" : t("open")}
        </Button>
      </ReasonTooltip>
    );
  }

  async function open(applicationId) {
    /*
     * No tab until the login URL exists, then straight onto it; the button
     * shows the wait. No fallback toast: see lib/browser/new-tab.js.
     */
    setOpening(true);
    try {
      const { data } = await phpmyadminSso(database.id, undefined, applicationId);
      const url = data?.redirect_url;
      if (!url) throw new Error("no url");

      openUrlInNewTab(url);
    } catch (error) {
      // The API's message names the reason: no phpMyAdmin site, or an
      // unsupported engine.
      toast.error(apiMessage(error, t("failed")));
    } finally {
      setOpening(false);
    }
  }

  const icon = opening ? (
    <Loader2 className="size-4 animate-spin" />
  ) : (
    <TableProperties className="size-4" />
  );

  // Labelled everywhere: a bare external-link icon reads as a link to the
  // database page, and a tooltip needs hover.
  const label = opening ? t("signingShort") : compact ? "phpMyAdmin" : t("open");

  /*
   * More than one installation: a menu asks which, instead of opening the
   * lowest id. `onSelect` is the click the new tab rides on.
   */
  if (sites !== null && sites.length > 1) {
    return (
      <DisabledReasonProvider reason={canManage ? null : t("noPermission")}>
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button
              type="button"
              variant="outline"
              size="sm"
              disabled={!canManage || opening}
            >
              {icon}
              {label}
              <ChevronDown className="size-4 opacity-60" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="max-w-[min(20rem,90vw)]">
            <DropdownMenuLabel>{t("choose")}</DropdownMenuLabel>
            {sites.map((site) => (
              <DropdownMenuItem
                key={site.id}
                onSelect={() => open(site.id)}
                // The domain is what tells two installations apart.
                className="font-mono text-xs wrap-anywhere"
              >
                {site.domain}
              </DropdownMenuItem>
            ))}
          </DropdownMenuContent>
        </DropdownMenu>
      </DisabledReasonProvider>
    );
  }

  return (
    <DisabledReasonProvider reason={canManage ? null : t("noPermission")}>
      {/* Arrow function, not a bare reference: `onClick={open}` hands the
          click event straight to the site-id parameter. */}
      <Button
        type="button"
        variant="outline"
        size="sm"
        onClick={() => open()}
        disabled={!canManage || opening}
      >
        {icon}
        {label}
      </Button>
    </DisabledReasonProvider>
  );
}

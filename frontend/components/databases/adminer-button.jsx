"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import {
  DisabledReasonProvider,
  ReasonTooltip,
} from "@/components/ui/reason-tooltip";
import { toast } from "sonner";
import Link from "@/components/ui/app-link";
import { ChevronDown, Download, Loader2, PanelsTopLeft } from "lucide-react";
import { adminerSso } from "@/lib/api/databases";
import { adminerState } from "@/lib/databases/adminer-state";
import { userCount } from "@/lib/databases/phpmyadmin-state";
import { openUrlInNewTab } from "@/lib/browser/new-tab";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { MenuItemHint } from "@/components/data-table/menu-item-hint";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuTrigger,
  DropdownMenuPortal,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
} from "@/components/ui/dropdown-menu";

// Adminer's one-click sign-in, the sibling of PhpmyadminButton and the same flow:
// the token lives 60s and is single-use, so the tab opens only on the URL.
export function AdminerButton({
  database,
  canManage,
  compact = false,
  // Card overflow menus reuse the same installation and sign-in flow.
  menuItem = false,
  variant = "outline",
  size = "sm",
  // Null when the lookup failed (NOT "none").
  sites = null,
}) {
  const t = useTranslations("databases.adminer");
  const [opening, setOpening] = useState(false);
  const installed = sites === null ? null : sites.length > 0;

  const state = adminerState({
    engine: database.engine,
    driver: database.driver,
    installed,
    users: userCount(database),
  });

  // The session it opens is the database user's and can write: `database` manage.
  if (state === "hidden" || !canManage) return null;

  const Action = menuItem ? DropdownMenuItem : Button;
  const actionProps = menuItem ? {} : { variant, size };

  if (state === "install") {
    return (
      <Action asChild {...actionProps}>
        <Link href="/applications/create?type=adminer">
          <Download className="size-4" />
          {t("install")}
        </Link>
      </Action>
    );
  }

  if (state === "needs-user") {
    if (menuItem) {
      return (
        <MenuItemHint hint={t("needsUser")}>
          <DropdownMenuItem disabled>
            <PanelsTopLeft className="size-4" />
            {t("open")}
          </DropdownMenuItem>
        </MenuItemHint>
      );
    }
    return (
      <ReasonTooltip reason={t("needsUser")}>
        <Button type="button" variant={variant} size={size} disabled>
          <PanelsTopLeft className="size-4" />
          {compact ? "Adminer" : t("open")}
        </Button>
      </ReasonTooltip>
    );
  }

  async function open(applicationId) {
    setOpening(true);
    try {
      const { data } = await adminerSso(database.id, undefined, applicationId);
      const url = data?.redirect_url;
      if (!url) throw new Error("no url");

      openUrlInNewTab(url);
    } catch (error) {
      // The API names the reason: no Adminer, no HTTPS, a password the panel never knew.
      toast.error(apiMessage(error, t("failed")));
    } finally {
      setOpening(false);
    }
  }

  const icon = opening ? (
    <Loader2 className="size-4 animate-spin" />
  ) : (
    <PanelsTopLeft className="size-4" />
  );
  const label = opening ? t("signingShort") : compact && !menuItem ? "Adminer" : t("open");

  // Leave the menu open while signing in so the pending item blocks repeat requests.
  const select = (event, siteId) => {
    event.preventDefault();
    open(siteId);
  };

  if (sites !== null && sites.length > 1) {
    if (menuItem) {
      return (
        <DropdownMenuSub>
          <DropdownMenuSubTrigger disabled={opening}>
            {icon}
            {label}
          </DropdownMenuSubTrigger>
          <DropdownMenuPortal>
            <DropdownMenuSubContent className="max-w-[min(20rem,90vw)]">
              <DropdownMenuLabel>{t("choose")}</DropdownMenuLabel>
              {sites.map((site) => (
                <DropdownMenuItem
                  key={site.id}
                  disabled={opening}
                  onSelect={(event) => select(event, site.id)}
                  className="font-mono text-xs wrap-anywhere"
                >
                  {site.domain}
                </DropdownMenuItem>
              ))}
            </DropdownMenuSubContent>
          </DropdownMenuPortal>
        </DropdownMenuSub>
      );
    }
    return (
      <DisabledReasonProvider reason={canManage ? null : t("noPermission")}>
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button
              type="button"
              variant={variant}
              size={size}
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

  if (menuItem) {
    return (
      <DropdownMenuItem disabled={opening} onSelect={select}>
        {icon}
        {label}
      </DropdownMenuItem>
    );
  }

  return (
    <DisabledReasonProvider reason={canManage ? null : t("noPermission")}>
      {/* An arrow function: `onClick={open}` would pass the event as the site id. */}
      <Button
        type="button"
        variant={variant}
        size={size}
        onClick={() => open()}
        disabled={!canManage || opening}
      >
        {icon}
        {label}
      </Button>
    </DisabledReasonProvider>
  );
}

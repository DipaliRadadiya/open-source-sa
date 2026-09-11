import { useState } from "react";
import { useTranslations } from "next-intl";
import {
  DisabledReasonProvider,
  ReasonTooltip,
} from "@/components/ui/reason-tooltip";
import { toast } from "sonner";
import Link from "next/link";
import { ChevronDown, Download, Loader2, TableProperties } from "lucide-react";
import { phpmyadminSso } from "@/lib/api/databases";
import { phpmyadminState, userCount } from "@/lib/databases/phpmyadmin-state";
import { placeholderDocument } from "@/lib/databases/phpmyadmin-placeholder";
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
 * `document.write` rather than DOM building: the handle is an `about:blank`
 * that has never navigated, and this is the one API that reliably replaces its
 * document before `location.replace` follows. It inherits this page's origin,
 * so writing to it is permitted — severing `opener` does not change that.
 */
function paintPlaceholder(tab, message) {
  tab.document.write(placeholderDocument(message));
  tab.document.close();
}

/**
 * Open this database in phpMyAdmin, already logged in.
 *
 * The token in the returned URL lives for 60 seconds and is consumed once, so
 * the browser is sent straight there — it is not a link to render, copy or
 * come back to later.
 *
 * `window.open` rather than a redirect: leaving the panel to look at a table
 * is not the same as navigating away from it, and the popup keeps the page
 * you were on. The call has to happen first, though, so the popup is opened
 * before the await — a window opened after one is blocked as unsolicited.
 *
 * `noopener` must NOT go in the features string: per spec `window.open`
 * returns null when it is present, so there is no handle to point at the URL
 * once the token arrives. That null read as "the popup was blocked", the
 * fallback redirected the current tab, and the one-click login replaced the
 * panel instead of opening beside it — every time, in every browser. The
 * opener is severed on the handle instead, which does the same job and still
 * returns the window.
 *
 * And when the popup genuinely is blocked, the current tab is left alone. The
 * old fallback navigated it, which produced exactly the thing this button
 * exists to avoid: an empty tab beside a panel that had been replaced by
 * phpMyAdmin. A blocked popup can only be reopened by a real click, so the
 * toast carries one — the token is still good for the rest of its minute.
 *
 * Hidden entirely for any engine phpMyAdmin cannot speak — MongoDB, and now
 * PostgreSQL. The API says so with a 422, but a button whose only outcome is
 * an error is not a feature. The test is the DRIVER, matching the endpoint's
 * own guard, so a fifth engine hides correctly without this file being touched.
 */
export function PhpmyadminButton({
  database,
  canManage,
  compact = false,
  /*
   * Every active phpMyAdmin site on this server, or null when the lookup
   * failed — which is NOT the same as "there isn't one" and must not change
   * what the button offers.
   *
   * The list rather than a boolean, because the button has to know whether
   * there is a choice to offer before anyone clicks it. A boolean could only
   * say that one exists, and the panel would go on opening whichever the API
   * picked.
   */
  sites = null,
}) {
  const t = useTranslations("databases.phpmyadmin");
  const [opening, setOpening] = useState(false);
  const installed = sites === null ? null : sites.length > 0;

  /*
   * The two refusals the panel can see coming, taken from the SSO endpoint's
   * own guards: no active phpMyAdmin site, and a database with no user to sign
   * in as. The rest — a site sharing the server-wide PHP pool, a link that
   * cannot be prepared — are only knowable by asking, so they stay as the
   * toast that already handles them.
   *
   * The decision lives in lib/databases/phpmyadmin-state.js: it is pure, and
   * neither test box has a database to render these states against.
   */
  const state = phpmyadminState({
    engine: database.engine,
    driver: database.driver,
    installed,
    users: userCount(database),
  });

  if (state === "hidden") return null;

  // Nothing to open: offer the install instead. A link, not a fetch — this
  // goes to the ordinary create-application flow with the type already chosen,
  // so the domain and the confirmation stay the user's.
  if (state === "install") {
    return (
      <ReasonTooltip reason={canManage ? null : t("noPermission")}>
        <Button
          asChild={canManage}
          variant="outline"
          size="sm"
          disabled={!canManage}
        >
          {canManage ? (
            <Link href="/applications/create?type=phpmyadmin">
              <Download className="size-4" />
              {t("install")}
            </Link>
          ) : (
            <>
              <Download className="size-4" />
              {t("install")}
            </>
          )}
        </Button>
      </ReasonTooltip>
    );
  }

  // Installed, but this database has nobody to sign in as. phpMyAdmin
  // authenticates as a database user; without one there is no login to make.
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
    setOpening(true);
    // Opened synchronously off the click, then pointed somewhere once the
    // token arrives. Opening it after the await is a popup the browser did
    // not see the user ask for.
    const tab = window.open("", "_blank");
    try {
      // Same protection `noopener` would have given, applied where it does not
      // cost the handle: the new tab cannot reach back through `window.opener`.
      // Inside the try because it is a setter on a window the browser may have
      // already disowned — a throw here used to take the whole click with it.
      if (tab) tab.opener = null;

      // Give the placeholder something to say.
      //
      // The blank frame cannot be removed — the tab has to exist before the
      // await or the browser blocks it, and the URL does not exist until after
      // — but it does not have to be `about:blank`. Unexplained, it reads as a
      // tab that opened by mistake, which is what it was reported as.
      //
      // Same reason as the line above for being inside the try: this touches a
      // document in a window the browser may already have disowned, and a
      // throw here would cost the click that is fetching a 60-second token.
      if (tab) paintPlaceholder(tab, t("signingIn"));

      const { data } = await phpmyadminSso(
        database.id,
        undefined,
        applicationId,
      );
      const url = data?.redirect_url;
      if (!url) throw new Error("no url");

      if (tab) {
        // `replace`, so the blank placeholder is not left in the new tab's
        // history for Back to return to.
        tab.location.replace(url);
        return;
      }

      // Popup blocked. The panel stays exactly where it is; a click the
      // browser can see is the only way to open the tab now.
      toast.error(t("blocked"), {
        duration: 20000,
        action: {
          label: t("openAnyway"),
          onClick: () => window.open(url, "_blank", "noopener"),
        },
      });
    } catch (error) {
      tab?.close();
      // The API's own sentence: it names which of the two reasons applies —
      // no phpMyAdmin site on this server, or an engine it cannot talk to.
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

  // Labelled everywhere, including in the row. A bare external-link arrow is
  // the icon for "opens a site", so it read as a link to the database's own
  // page — and the tooltip that explained it needs a hover, which a phone
  // does not have. A word costs a little width and removes the guessing.
  const label = compact ? "phpMyAdmin" : t("open");

  /*
   * More than one installation: the button asks which, instead of silently
   * opening whichever has the lowest id.
   *
   * A menu rather than a dialog. There is one thing to decide and no way to
   * get it wrong — the wrong choice costs a click, not data — and a modal for
   * that is heavier than the decision.
   *
   * `onSelect` still counts as the click that opened the tab, which is the
   * whole reason the choice can live here at all: a tab opened outside a
   * gesture is a blocked popup.
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
                // The domain is the only thing that tells two installations
                // apart — the name is whatever someone typed, and both are
                // called phpMyAdmin often enough to be useless here.
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

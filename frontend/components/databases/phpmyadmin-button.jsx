"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { DisabledReasonProvider } from "@/components/ui/reason-tooltip";
import { toast } from "sonner";
import { Loader2, TableProperties } from "lucide-react";
import { phpmyadminSso } from "@/lib/api/databases";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";

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
 * Hidden entirely for MongoDB, which phpMyAdmin does not support. The API says
 * so with a 422, but a button whose only outcome is an error is not a feature.
 */
export function PhpmyadminButton({ database, canManage, compact = false }) {
  const t = useTranslations("databases.phpmyadmin");
  const [opening, setOpening] = useState(false);

  if (database.engine === "mongodb") return null;

  async function open() {
    setOpening(true);
    // Opened synchronously off the click, then pointed somewhere once the
    // token arrives. Opening it after the await is a popup the browser did
    // not see the user ask for.
    const tab = window.open("", "_blank");
    // Same protection `noopener` would have given, applied where it does not
    // cost the handle: the new tab cannot reach back through `window.opener`.
    if (tab) tab.opener = null;
    try {
      const { data } = await phpmyadminSso(database.id);
      if (!data?.redirect_url) throw new Error("no url");
      if (tab) tab.location.href = data.redirect_url;
      else window.location.href = data.redirect_url;
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
  if (compact) {
    return (
      <Button
        type="button"
        variant="outline"
        size="sm"
        onClick={open}
        disabled={!canManage || opening}
      >
        {icon}
        phpMyAdmin
      </Button>
    );
  }

  return (
    <DisabledReasonProvider reason={canManage ? null : t("noPermission")}>
      <Button type="button" variant="outline" size="sm" onClick={open} disabled={!canManage || opening}>
        {icon}
        {t("open")}
      </Button>
    </DisabledReasonProvider>
  );
}

"use client";

import { useState } from "react";
import Link from "next/link";
import { useTranslations } from "next-intl";
import { History, Loader2, ExternalLink } from "lucide-react";
import { getMyActivityByType } from "@/lib/api/activity";
import { myActivityResponseSchema } from "@/lib/schemas/account";
import { humanizeActivity, actionBadgeVariant } from "@/lib/activity/labels";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { FormModal } from "@/components/ui/form-modal";
import { LoadFailed } from "@/components/data-table/load-failed";

/**
 * What changed on this firewall, and when.
 *
 * Scope is stated plainly in the dialog, because it is narrower than people will
 * assume: `GET /activity-log` returns **the caller's own** rows and carries no
 * user field, so this cannot say who else changed a rule. Admins get a link to
 * the admin log, which does span users. Claiming "who opened this port" from an
 * endpoint that only knows about you would be a lie the UI can't back up.
 *
 * Fetched on open rather than with the page: nobody needs the history until they
 * ask for it, and it would otherwise cost every visitor a request.
 */
export function HistoryDialog({ isAdmin }) {
  const t = useTranslations("firewall");
  const [open, setOpen] = useState(false);
  const [state, setState] = useState({ loading: false, failed: false, entries: [] });

  async function load() {
    setState({ loading: true, failed: false, entries: [] });
    try {
      const { data } = await getMyActivityByType("firewall");
      const parsed = myActivityResponseSchema.safeParse(data);
      if (!parsed.success) {
        setState({ loading: false, failed: true, entries: [] });
        return;
      }
      setState({ loading: false, failed: false, entries: parsed.data.activity_log });
    } catch {
      setState({ loading: false, failed: true, entries: [] });
    }
  }

  return (
    <>
      <Button
        variant="outline"
        onClick={() => {
          setOpen(true);
          load();
        }}
      >
        <History className="size-4" />
        {t("history.action")}
      </Button>

      <FormModal
        open={open}
        onOpenChange={setOpen}
        icon={History}
        title={t("history.title")}
        description={t("history.description")}
        footer={
          <>
            {/* Only admins can see everyone's changes, so only they get the
                link — offering it to someone who'd be redirected is worse than
                not offering it. */}
            {isAdmin ? (
              <Button variant="outline" asChild className="mr-auto">
                <Link href="/admin/activity-log?type=firewall">
                  <ExternalLink className="size-4" />
                  {t("history.everyone")}
                </Link>
              </Button>
            ) : null}
            <Button variant="outline" onClick={() => setOpen(false)}>
              {t("common.close")}
            </Button>
          </>
        }
      >
        {state.loading ? (
          <p className="flex items-center justify-center gap-2 py-10 text-sm text-muted-foreground">
            <Loader2 className="size-4 animate-spin" />
            {t("history.loading")}
          </p>
        ) : state.failed ? (
          <LoadFailed description={t("history.failed")} />
        ) : state.entries.length === 0 ? (
          <p className="py-10 text-center text-sm text-muted-foreground">
            {t("history.empty")}
          </p>
        ) : (
          <ul className="divide-y">
            {state.entries.map((entry) => (
              <li key={entry.id} className="flex items-start justify-between gap-3 py-2.5">
                <div className="min-w-0 space-y-1">
                  {/* `description` is the server's finished sentence; the
                      humanized verb is only a fallback for rows without one. */}
                  <p className="text-sm leading-snug">
                    {entry.description || humanizeActivity(entry.action)}
                  </p>
                  <Badge variant={actionBadgeVariant(entry.action)} className="font-normal">
                    {humanizeActivity(entry.action)}
                  </Badge>
                </div>
                <span className="shrink-0 whitespace-nowrap text-xs text-muted-foreground">
                  {entry.created_at_human || entry.created_at || "—"}
                </span>
              </li>
            ))}
          </ul>
        )}
      </FormModal>
    </>
  );
}

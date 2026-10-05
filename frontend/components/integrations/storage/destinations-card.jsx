"use client";

import { useState } from "react";
import { usePendingKeys } from "@/hooks/use-pending-keys";
import { useRefresh } from "@/hooks/use-refresh";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { HardDrive, Plus, Trash2 } from "lucide-react";
import { deleteDestination } from "@/lib/api/storage";
import { probeDestination } from "@/lib/storage/probe";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { useConfirmAction } from "@/hooks/use-confirm-action";
import { InfoHint } from "@/components/ui/info-hint";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { DestinationRow } from "@/components/integrations/storage/destination-row";
import { ConnectDestinationDialog } from "@/components/integrations/storage/connect-dialog";
import { EditDestinationDialog } from "@/components/integrations/storage/edit-dialog";
import { ReplaceCredentialsDialog } from "@/components/integrations/storage/replace-credentials-dialog";

// Named in the empty state so "S3-compatible" is concrete; "other" is omitted.
const EMPTY_STATE_PROVIDERS = ["aws", "r2", "b2", "wasabi", "spaces"];

// A plain card of rows rather than a DataTable: the list is always short.
export function DestinationsCard({ destinations = [], canManage, oauthRedirectUri = null }) {
  const t = useTranslations("storage");
  const { refreshAndWait } = useRefresh();
  const [connecting, setConnecting] = useState(false);
  const [editing, setEditing] = useState(null);
  const [replacing, setReplacing] = useState(null);
  // A refused delete keeps the dialog open with the reason: the backend refuses
  // while a backup target still points here.
  const removal = useConfirmAction();
  // Tests can run side by side, one spinner each.
  const testing = usePendingKeys();
  // Keyed by id, cleared on a fresh test; the stored verdict is in DestinationRow.
  const [results, setResults] = useState({});

  async function test(destination) {
    if (testing.isPending(destination.id)) return;
    testing.start(destination.id);
    setResults((prev) => ({ ...prev, [destination.id]: null }));
    // 200 does NOT mean the connection works — the verdict is in the body.
    const verdict = await probeDestination(
      destination.id,
      t("row.testFailed"),
      t("row.testNotRun"),
    );
    setResults((prev) => ({ ...prev, [destination.id]: verdict }));
    testing.finish(destination.id);
  }

  async function remove() {
    await removal.run(() => deleteDestination(removal.target.id), {
      fallback: t("delete.failed"),
      onDone: async () => {
        await refreshAndWait();
        toast.success(t("delete.removed"));
      },
    });
  }

  // Shared by the header and the empty state so the two cannot differ.
  const addButton = (
    <ReasonTooltip reason={canManage ? null : t("noPermission")}>
      <Button type="button" disabled={!canManage} onClick={() => setConnecting(true)}>
        <Plus className="size-4" />
        {t("card.add")}
      </Button>
    </ReasonTooltip>
  );

  return (
    <Card className="gap-0 overflow-hidden py-0">
      {/* Same header shape as the Git integration card. The add button only
          shows once there is a list; the empty state carries its own. */}
      <div className="flex flex-wrap items-center justify-between gap-3 border-b bg-muted/20 px-5 py-3">
        <div className="flex items-center gap-2.5">
          <span className="flex shrink-0 items-center justify-center text-muted-foreground">
            <HardDrive className="size-3.5" />
          </span>
          <div>
            <div className="flex items-center gap-1.5">
              <p className="text-sm font-medium">{t("card.title")}</p>
              {/* Test writes, reads back and deletes a file, so a read-only key never
                  passes. Popover, not tooltip, so it works on touch. */}
              <InfoHint label={t("card.whatTestDoes")}>
                <p className="text-xs leading-relaxed">{t("card.testExplained")}</p>
              </InfoHint>
            </div>
            <p className="text-xs text-muted-foreground">{t("card.subtitle")}</p>
          </div>
        </div>
        {destinations.length > 0 ? addButton : null}
      </div>

      <CardContent className="px-5 py-0">
        {destinations.length === 0 ? (
          // Same empty-state structure as the Git integration card: chip,
          // title, description, reassurance, qualifying providers, steps, action.
          <div className="mx-auto flex max-w-lg flex-col items-center gap-5 py-10 text-center sm:py-12">
            <span className="flex size-12 items-center justify-center rounded-xl bg-primary/10 text-primary ring-1 ring-primary/15">
              <HardDrive className="size-6" aria-hidden />
            </span>
            <div className="space-y-2">
              <p className="text-base font-semibold tracking-tight">{t("empty.title")}</p>
              <p className="max-w-md text-sm leading-6 text-muted-foreground">{t("empty.body")}</p>
              {/* Keys are encrypted at rest and only used for this server's backups. */}
              <p className="max-w-md text-xs leading-5 text-muted-foreground">
                {t("empty.reassurance")}
              </p>
            </div>

              {/* Qualifying providers, as text (no logos for these). */}
            <div className="flex flex-wrap justify-center gap-2">
              {EMPTY_STATE_PROVIDERS.map((provider) => (
                <span
                  key={provider}
                  className="inline-flex items-center gap-1.5 rounded-md border bg-background px-2.5 py-1.5 text-xs font-medium text-muted-foreground"
                >
                  {t(`form.providers.${provider}`)}
                </span>
              ))}
            </div>

            <div className="w-full rounded-xl border bg-muted/30 p-4 text-left sm:p-5">
              <p className="text-sm font-medium">{t("empty.stepsTitle")}</p>
              <ol className="mt-3 space-y-3 text-sm text-muted-foreground">
                {[t("empty.step1"), t("empty.step2"), t("empty.step3")].map((step, index) => (
                  <li key={step} className="flex items-start gap-3">
                    <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-background text-xs font-medium text-foreground ring-1 ring-border">
                      {index + 1}
                    </span>
                    <span className="leading-5">{step}</span>
                  </li>
                ))}
              </ol>
            </div>

            {addButton}
          </div>
        ) : (
          <div className="divide-y">
            {destinations.map((destination) => (
              <DestinationRow
                key={destination.id}
                destination={destination}
                canManage={canManage}
                testing={testing.isPending(destination.id)}
                result={results[destination.id]}
                onTest={() => test(destination)}
                onEdit={() => setEditing(destination)}
                onReplace={() => setReplacing(destination)}
                onDelete={() => removal.open(destination)}
              />
            ))}
          </div>
        )}
      </CardContent>

      <ConnectDestinationDialog
        open={connecting}
        onOpenChange={setConnecting}
        oauthRedirectUri={oauthRedirectUri}
      />
      {editing ? (
        <EditDestinationDialog
          oauthRedirectUri={oauthRedirectUri}
          destination={editing}
          open={Boolean(editing)}
          onOpenChange={(open) => !open && setEditing(null)}
        />
      ) : null}
      {replacing ? (
        <ReplaceCredentialsDialog
          destination={replacing}
          open={Boolean(replacing)}
          onOpenChange={(open) => !open && setReplacing(null)}
        />
      ) : null}

      <ConfirmDialog
        open={removal.isOpen}
        onOpenChange={removal.setOpen}
        icon={Trash2}
        tone="destructive"
        title={t("delete.title")}
        description={t("delete.description", { name: removal.target?.name ?? "" })}
        cancelLabel={t("delete.cancel")}
        confirmLabel={t("delete.confirm")}
        confirmVariant="destructive"
        pending={removal.pending}
        error={removal.error}
        onConfirm={remove}
      >
        {/* The API refuses while anything backs up or is stored here, and names it in
            the error. */}
        <p className="text-sm text-muted-foreground">{t("delete.warning")}</p>
      </ConfirmDialog>
    </Card>
  );
}

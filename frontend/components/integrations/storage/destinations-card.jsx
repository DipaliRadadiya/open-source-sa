"use client";

import { HeadIcon } from "@/components/ui/head-icon";
import { useState } from "react";
import { usePendingKeys } from "@/hooks/use-pending-keys";
import { useRefresh } from "@/hooks/use-refresh";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { HardDrive, Plus, Trash2 } from "lucide-react";
import { PRESETS } from "@/lib/storage/providers";
import { deleteDestination } from "@/lib/api/storage";
import { probeDestination } from "@/lib/storage/probe";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Note } from "@/components/ui/note";
import { useConfirmAction } from "@/hooks/use-confirm-action";
import { InfoHint } from "@/components/ui/info-hint";
import { DENIED, ReasonTooltip } from "@/components/ui/reason-tooltip";
import { DestinationRow } from "@/components/integrations/storage/destination-row";
import { ConnectDestinationDialog } from "@/components/integrations/storage/connect-dialog";
import { EditDestinationDialog } from "@/components/integrations/storage/edit-dialog";
import { ReplaceCredentialsDialog } from "@/components/integrations/storage/replace-credentials-dialog";

const EMPTY_STATE_PROVIDERS = PRESETS.filter((preset) => !preset.legacy).map((preset) => preset.value);

export function DestinationsCard({ destinations = [], canManage, oauthRedirectUri = null, localSettings = null, usage = null }) {
  const t = useTranslations("storage");
  const { refreshAndWait } = useRefresh();
  const [connecting, setConnecting] = useState(false);
  const [connectPreset, setConnectPreset] = useState(null);
  const [editing, setEditing] = useState(null);
  const [replacing, setReplacing] = useState(null);
  const removal = useConfirmAction();
  const testing = usePendingKeys();
  const [results, setResults] = useState({});

  async function test(destination) {
    if (testing.isPending(destination.id)) return;
    testing.start(destination.id);
    setResults((prev) => ({ ...prev, [destination.id]: null }));
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

  const addButton = (
    <ReasonTooltip reason={canManage ? null : DENIED}>
      <Button
        type="button"
        disabled={!canManage}
        onClick={() => {
          setConnectPreset(null);
          setConnecting(true);
        }}
      >
        <Plus className="size-4" />
        {t("card.add")}
      </Button>
    </ReasonTooltip>
  );

  return (
    <Card className="gap-0 overflow-hidden py-0">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3.5">
        <div className="flex items-start gap-3">
          <HeadIcon icon={HardDrive} />
          <div>
            <div className="flex items-center gap-1.5">
              <h2 className="text-[15px] font-semibold tracking-tight">{t("card.title")}</h2>
              <InfoHint label={t("card.whatTestDoes")}>
                <p className="text-xs leading-relaxed">{t("card.testExplained")}</p>
              </InfoHint>
            </div>
            <p className="text-sm text-muted-foreground">{t("card.subtitle")}</p>
          </div>
        </div>
        {destinations.length > 0 ? addButton : null}
      </div>

      <CardContent className="px-5 py-0">
        {destinations.length === 0 ? (
          <div className="grid gap-6 py-8 md:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] md:items-center md:gap-10">
            <div className="flex flex-col items-center gap-4 text-center md:items-start md:text-left">
              <span className="flex size-12 items-center justify-center rounded-xl bg-primary/10 text-primary ring-1 ring-primary/15">
                <HardDrive className="size-6" aria-hidden />
              </span>
              <div className="space-y-2">
                <p className="text-[15px] font-semibold tracking-tight">{t("empty.title")}</p>
                <p className="text-sm leading-6 text-muted-foreground">{t("empty.body")}</p>
                <p className="text-xs leading-5 text-muted-foreground">{t("empty.reassurance")}</p>
              </div>
              {addButton}
            </div>

            <div className="rounded-xl border bg-muted/30 p-4 sm:p-5">
              <p className="text-sm font-medium">{t("empty.stepsTitle")}</p>
              <ol className="mt-3 space-y-3 text-sm text-muted-foreground">
                {[t("empty.step1"), t("empty.step2"), t("empty.step3")].map((step, index) => (
                  <li key={step} className="flex items-start gap-3">
                    <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-background text-xs font-medium text-foreground ring-1 ring-border">
                      {index + 1}
                    </span>
                    <div className="min-w-0 space-y-2">
                      <span className="block leading-5">{step}</span>
                      {index === 0 ? (
                        <div className="flex flex-wrap gap-1.5">
                          {EMPTY_STATE_PROVIDERS.map((provider) => {
                            const name = provider === "other" ? t("empty.otherS3") : t(`form.providers.${provider}`);
                            return (
                              <ReasonTooltip key={provider} reason={canManage ? null : DENIED}>
                                <Button
                                  type="button"
                                  variant="outline"
                                  size="xs"
                                  disabled={!canManage}
                                  aria-label={t("empty.addNamed", { name })}
                                  onClick={() => {
                                    setConnectPreset(provider);
                                    setConnecting(true);
                                  }}
                                >
                                  <Plus aria-hidden />
                                  {name}
                                </Button>
                              </ReasonTooltip>
                            );
                          })}
                        </div>
                      ) : null}
                    </div>
                  </li>
                ))}
              </ol>
            </div>
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
                usage={usage ? (usage[destination.id] ?? { applications: 0, lastBackup: null }) : null}
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
        key={connectPreset ?? "default"}
        initialPreset={connectPreset}
        open={connecting}
        onOpenChange={setConnecting}
        oauthRedirectUri={oauthRedirectUri}
        localSettings={localSettings}
        existingNames={destinations.map((destination) => destination.name)}
      />
      {editing ? (
        <EditDestinationDialog
          oauthRedirectUri={oauthRedirectUri}
          localSettings={localSettings}
          destination={editing}
          existingNames={destinations.filter((destination) => destination.id !== editing.id).map((destination) => destination.name)}
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
        title={t("delete.titleNamed", { name: removal.target?.name ?? "" })}
        description={t("delete.description", { name: removal.target?.name ?? "" })}
        cancelLabel={t("delete.cancel")}
        confirmLabel={t("delete.confirm")}
        confirmVariant="destructive"
        pending={removal.pending}
        error={removal.error}
        onConfirm={remove}
      >
        <Note>{t("delete.warning")}</Note>
      </ConfirmDialog>
    </Card>
  );
}

"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { HardDrive, Plus, Trash2 } from "lucide-react";
import { deleteDestination } from "@/lib/api/storage";
import { probeDestination } from "@/lib/storage/probe";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { InfoHint } from "@/components/ui/info-hint";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { DestinationRow } from "@/components/integrations/storage/destination-row";
import { ConnectDestinationDialog } from "@/components/integrations/storage/connect-dialog";
import { EditDestinationDialog } from "@/components/integrations/storage/edit-dialog";
import { ReplaceCredentialsDialog } from "@/components/integrations/storage/replace-credentials-dialog";

// Named in the empty state so "S3-compatible" stops being jargon. Same keys as
// the form's provider hints, minus "other" — which tells a newcomer nothing.
const EMPTY_STATE_PROVIDERS = ["aws", "r2", "b2", "wasabi", "spaces", "minio"];

/**
 * Where backups are sent.
 *
 * A short, bounded list — a plain card of rows rather than a DataTable: nobody
 * has forty of these, and sorting or paginating three buckets is chrome for
 * its own sake.
 */
export function DestinationsCard({ destinations = [], canManage }) {
  const t = useTranslations("storage");
  const router = useRouter();
  const [connecting, setConnecting] = useState(false);
  const [editing, setEditing] = useState(null);
  const [replacing, setReplacing] = useState(null);
  const [deleting, setDeleting] = useState(null);
  const [removing, setRemoving] = useState(false);
  const [testingId, setTestingId] = useState(null);
  // Keyed by id, cleared on a fresh test. Never persisted — see DestinationRow.
  const [results, setResults] = useState({});

  async function test(destination) {
    setTestingId(destination.id);
    setResults((prev) => ({ ...prev, [destination.id]: null }));
    // 200 does NOT mean the connection works — the verdict is in the body.
    const verdict = await probeDestination(destination.id, t("row.testFailed"));
    setResults((prev) => ({ ...prev, [destination.id]: verdict }));
    setTestingId(null);
  }

  async function remove() {
    setRemoving(true);
    try {
      await deleteDestination(deleting.id);
      toast.success(t("delete.removed"));
      setDeleting(null);
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("delete.failed")));
    } finally {
      setRemoving(false);
    }
  }

  // Defined once and used in both the header and the empty state, the way the
  // Git card does it — two copies is how one of them ends up a different size
  // from the other, which is exactly what happened here first time round.
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
      {/* Same header shape as the Git integration card next door: tinted icon
          chip, title, subtitle — and the add button only once there is a list
          to add to, since the empty state already carries that call to
          action and two of them is the same offer twice. */}
      <div className="flex flex-wrap items-center justify-between gap-3 border-b bg-muted/20 px-5 py-3">
        <div className="flex items-center gap-2.5">
          <span className="flex size-7 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
            <HardDrive className="size-3.5" />
          </span>
          <div>
            <div className="flex items-center gap-1.5">
              <p className="text-sm font-medium">{t("card.title")}</p>
              {/* What "Test" actually does, available without failing first —
                  it writes a small file, reads it back and deletes it, which
                  is why a read-only key never passes. A popover, not a
                  tooltip, so it opens on a phone too. */}
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
          // Explains, shows what one is for, and offers the one action —
          // rather than an empty rule with nothing to do about it.
          // Structurally the same empty state as the Git integration card:
          // chip, title, description, a line of reassurance about what the
          // credential is used for, the providers that qualify, then the three
          // steps — and only then the action. The first version here had just
          // the chip and a button, which looked like the same screen but gave
          // a first-time user none of the same help.
          <div className="mx-auto flex max-w-lg flex-col items-center gap-5 py-10 text-center sm:py-12">
            <span className="flex size-12 items-center justify-center rounded-xl bg-primary/10 text-primary ring-1 ring-primary/15">
              <HardDrive className="size-6" aria-hidden />
            </span>
            <div className="space-y-2">
              <p className="text-base font-semibold tracking-tight">{t("empty.title")}</p>
              <p className="max-w-md text-sm leading-6 text-muted-foreground">{t("empty.body")}</p>
              {/* Git says "we only read, never push" here. The equivalent truth
                  for storage: the keys are encrypted at rest and only ever used
                  for this server's own backups. */}
              <p className="max-w-md text-xs leading-5 text-muted-foreground">
                {t("empty.reassurance")}
              </p>
            </div>

            {/* Which providers qualify — the same job Git's logo chips do.
                Text only, because we have no logos for these and inventing
                them is not worth a round of licence checking. */}
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
                testing={testingId === destination.id}
                result={results[destination.id]}
                onTest={() => test(destination)}
                onEdit={() => setEditing(destination)}
                onReplace={() => setReplacing(destination)}
                onDelete={() => setDeleting(destination)}
              />
            ))}
          </div>
        )}
      </CardContent>

      <ConnectDestinationDialog open={connecting} onOpenChange={setConnecting} />
      {editing ? (
        <EditDestinationDialog
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
        open={Boolean(deleting)}
        onOpenChange={(open) => !open && setDeleting(null)}
        icon={Trash2}
        tone="destructive"
        title={t("delete.title")}
        description={t("delete.description", { name: deleting?.name ?? "" })}
        cancelLabel={t("delete.cancel")}
        confirmLabel={t("delete.confirm")}
        confirmVariant="destructive"
        pending={removing}
        onConfirm={remove}
      >
        {/* The backend deletes without checking whether a backup target points
            here, and there is no endpoint that lists targets across
            applications — so the panel cannot name the sites that would be
            affected. Saying that plainly beats a confident "this is safe". */}
        <p className="text-sm text-muted-foreground">{t("delete.warning")}</p>
      </ConfirmDialog>
    </Card>
  );
}

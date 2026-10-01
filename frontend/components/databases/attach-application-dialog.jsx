import { useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Link2, Loader2 } from "lucide-react";
import { attachDatabase } from "@/lib/api/databases";
import { apiMessage } from "@/lib/api/error-message";
import { applicationOptions } from "@/lib/backups/database-availability";
import { acceptedEnginesFor, engineAccepted } from "@/lib/databases/engine-acceptance";
import { Button } from "@/components/ui/button";
import { Combobox } from "@/components/ui/combobox";
import { FormModal } from "@/components/ui/form-modal";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";

// Attach, move and detach in one: the API takes a single nullable `application_id`.
// Decides what gets backed up; rewrites no connection string.
export function AttachApplicationDialog({
  database,
  open,
  onOpenChange,
  applications = [],
  databaseCounts = null,
  databasesKnown = false,
  siteTypes = [],
}) {
  const t = useTranslations("databases.attach");
  const tEngines = useTranslations("databases.engines");
  const { refreshAndWait } = useRefresh();
  // null means untouched, so the field reads the database's current site
  // without an effect to re-seed state on open.
  const [picked, setPicked] = useState(null);
  const [pending, setPending] = useState(false);
  // Field-level: the API's refusals name a next action a toast would hide.
  const [error, setError] = useState(null);

  const current = database?.application_id ?? null;
  const value = picked ?? (current === null ? "" : String(current));

  // Every close resets. Opening comes from the parent's button and never
  // routes through here, so no seeding is needed.
  function handleOpenChange(next) {
    if (!next) {
      setPicked(null);
      setError(null);
    }
    onOpenChange?.(next);
  }

  const chosen = value === "" ? null : value;
  const unchanged = String(current ?? "") === String(chosen ?? "");

  async function submit(event) {
    event.preventDefault();
    if (unchanged) return;

    setPending(true);
    setError(null);
    try {
      await attachDatabase(database.id, chosen);
      await refreshAndWait();
      toast.success(chosen ? t("attached") : t("detached"));
      handleOpenChange(false);
    } catch (caught) {
  // The API returns both refusals on `application_id`.
      const field = caught.response?.data?.errors?.application_id?.[0];
      setError(field ?? apiMessage(caught, t("failed")));
    } finally {
      setPending(false);
    }
  }

  return (
    <FormModal
      open={open}
      onOpenChange={handleOpenChange}
      asForm
      onSubmit={submit}
      icon={Link2}
      title={t("title")}
      description={t("subtitle", { name: database?.name ?? "" })}
      footer={
        <>
          <Button
            type="button"
            variant="outline"
            disabled={pending}
            onClick={() => handleOpenChange(false)}
          >
            {t("cancel")}
          </Button>
          {/* Explains why Save is disabled when nothing changed; silent while
              saving. */}
          <ReasonTooltip reason={!pending && unchanged ? t("unchanged") : null}>
            <Button type="submit" disabled={pending || unchanged}>
              {pending && <Loader2 className="size-4 animate-spin" />}
              {pending ? t("saving") : t("submit")}
            </Button>
          </ReasonTooltip>
        </>
      }
    >
      <div className="space-y-2">
        <label className="text-sm font-medium" htmlFor="attach-application">
          {t("label")}
        </label>
        <Combobox
          id="attach-application"
          value={value}
          onChange={(next) => {
            setPicked(next);
            setError(null);
          }}
          disabled={pending}
          placeholder={t("none")}
          searchPlaceholder={t("search")}
          className="w-full"
          options={[
            { value: "", label: t("none") },
            ...applicationOptions(
              applications,
              // The current site must stay selectable, or the dialog opens on
              // a value its own list calls taken.
              excludeSelf(databaseCounts, current),
              databasesKnown,
              t("taken"),
              // The API refuses this pairing, so it is a blocked option with a reason.
              (application) =>
                engineAccepted({ application, siteTypes, engine: database?.engine })
                  ? undefined
                  : t("engineNotAccepted", {
                      engines: acceptedEnginesFor({ application, siteTypes })
                        .map((name) => (tEngines.has(name) ? tEngines(name) : name))
                        .join(" / "),
                    }),
            ),
          ]}
        />

        {error ? (
          <p role="alert" className="text-sm font-medium text-destructive">
            {error}
          </p>
        ) : null}

        {/* Required by the API docs: attaching does not make the site use it. */}
        <p className="text-xs text-muted-foreground">{t("hint")}</p>
      </div>
    </FormModal>
  );
}

// Excludes this database's own site, so it is not greyed out as "already has a database".
function excludeSelf(counts, applicationId) {
  if (!counts || applicationId === null || applicationId === undefined) return counts;

  const next = { ...counts };
  delete next[applicationId];
  return next;
}

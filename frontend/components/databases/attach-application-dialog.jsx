import { useState } from "react";
import { useRouter } from "next/navigation";
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

/**
 * Choose which site a database belongs to.
 *
 * The same modal covers attach, move and detach, because the API is one field:
 * `application_id` is the site, or null. Three verbs over one nullable value
 * would be three ways to describe the same request.
 *
 * The consequence is stated in the modal rather than after it, because it is
 * the reason anyone is here and the reason they might be about to be
 * disappointed: this decides what gets BACKED UP, and rewrites no connection
 * string anywhere.
 */
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
  const router = useRouter();
  // null means untouched, so the field simply reads the database. Holding the
  // current site in state instead would need an effect to re-seed it, and an
  // effect that writes state on open is a cascading render.
  const [picked, setPicked] = useState(null);
  const [pending, setPending] = useState(false);
  // Field-level, because both of the API's refusals name a next action
  // ("detach that one first") and a toast takes that away as it fades.
  const [error, setError] = useState(null);

  const current = database?.application_id ?? null;
  const value = picked ?? (current === null ? "" : String(current));

  // Every close resets, including the one after a successful save. The open is
  // left alone deliberately: it comes from the parent's own button, which never
  // routes through here, so seeding on open would need the effect this avoids.
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
      toast.success(chosen ? t("attached") : t("detached"));
      handleOpenChange(false);
      router.refresh();
    } catch (caught) {
      // The API returns both refusals on `application_id`, which is the field
      // the reader is looking at.
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
          {/* Off until the answer actually changes, which needs saying: an
              inert Save with the right site already showing reads as broken
              rather than as "there is nothing to do". Silent while saving —
              the spinner is its own explanation. */}
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
              // The site this database is ALREADY on must stay choosable, or
              // the dialog opens showing a value its own list calls taken.
              excludeSelf(databaseCounts, current),
              databasesKnown,
              t("taken"),
              // The API refuses this pairing outright, so it is a blocked
              // option with the reason rather than a refusal after the choice.
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

        {/* Required by the API docs in as many words: someone who attaches a
            database expecting their site to start using it has been misled. */}
        <p className="text-xs text-muted-foreground">{t("hint")}</p>
      </div>
    </FormModal>
  );
}

/**
 * The counts, minus this database's own site.
 *
 * Without this, reopening the dialog on an attached database shows its current
 * site greyed out as "already has a database" — true, and the database saying
 * it is this one.
 */
function excludeSelf(counts, applicationId) {
  if (!counts || applicationId === null || applicationId === undefined) return counts;

  const next = { ...counts };
  delete next[applicationId];
  return next;
}

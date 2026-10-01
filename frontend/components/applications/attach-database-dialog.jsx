import { useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Link2, Loader2 } from "lucide-react";
import { attachDatabase } from "@/lib/api/databases";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { Combobox } from "@/components/ui/combobox";
import { FormModal } from "@/components/ui/form-modal";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";

// Only unattached databases are offered, so none is silently moved off another site.
export function AttachDatabaseDialog({
  applicationId,
  applicationName,
  databases = [],
  open,
  onOpenChange,
}) {
  const t = useTranslations("applications.attachDatabase");
  const { refreshAndWait } = useRefresh();
  const [picked, setPicked] = useState(null);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState(null);

  const value = picked ?? "";

  function handleOpenChange(next) {
    if (!next) {
      setPicked(null);
      setError(null);
    }
    onOpenChange?.(next);
  }

  async function submit(event) {
    event.preventDefault();
    if (!value) return;

    setPending(true);
    setError(null);
    try {
      await attachDatabase(value, applicationId);
      await refreshAndWait();
      const name = databases.find((d) => String(d.id) === String(value))?.name ?? "";
      toast.success(t("done", { name }));
      handleOpenChange(false);
    } catch (caught) {
      setError(
        caught.response?.data?.errors?.application_id?.[0]
          ?? apiMessage(caught, t("failed")),
      );
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
      description={t("subtitle", { name: applicationName ?? "" })}
      footer={
        <>
          <Button type="button" variant="outline" disabled={pending} onClick={() => handleOpenChange(false)}>
            {t("cancel")}
          </Button>
          <ReasonTooltip reason={!pending && !value ? t("pickOne") : null}>
            <Button type="submit" disabled={pending || !value}>
              {pending && <Loader2 className="size-4 animate-spin" />}
              {pending ? t("saving") : t("submit")}
            </Button>
          </ReasonTooltip>
        </>
      }
    >
      <div className="space-y-2">
        <label className="text-sm font-medium" htmlFor="attach-db">{t("label")}</label>
        <Combobox
          id="attach-db"
          value={value}
          onChange={(next) => {
            setPicked(next);
            setError(null);
          }}
          disabled={pending}
          placeholder={t("placeholder")}
          searchPlaceholder={t("search")}
          className="w-full"
          options={databases.map((database) => ({
            value: String(database.id),
            label: database.name,
            hint: database.engine,
          }))}
        />

        {error ? (
          <p role="alert" className="text-sm font-medium text-destructive">{error}</p>
        ) : null}

        {/* Same hint as every attach surface: affects backups, rewrites no
            connection string. */}
        <p className="text-xs text-muted-foreground">{t("hint")}</p>
      </div>
    </FormModal>
  );
}

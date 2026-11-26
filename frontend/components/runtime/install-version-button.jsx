"use client";

import { useState } from "react";
import { usePathname, useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Download, Loader2, Plus, TriangleAlert } from "lucide-react";
import { LifecycleBadge } from "@/components/runtime/lifecycle-badge";
import { installPhpVersion } from "@/lib/api/php";
import { installNodeVersion } from "@/lib/api/node";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { DENIED, ReasonTooltip } from "@/components/ui/reason-tooltip";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { FormModal } from "@/components/ui/form-modal";
import { apiMessage } from "@/lib/api/error-message";
import { allInstalled, installOptions, resolveVersion } from "@/lib/runtime/install-options";

// A Server Component can't pass a function, so pick the installer here.
const INSTALL = { php: installPhpVersion, node: installNodeVersion };

export function InstallVersionButton({
  runtime,
  installable = [],
  catalogError = null,
  installed = [],
  canManage,
  lifecycleAvailable = false,
}) {
  const t = useTranslations(runtime);
  const router = useRouter();
  const pathname = usePathname();
  const [open, setOpen] = useState(false);
  const [pending, setPending] = useState(false);

  // Count it as installed until the refresh lands, so it can't be offered twice.
  const [started, setStarted] = useState([]);
  const listed = new Set((Array.isArray(installed) ? installed : []).map((item) => String(typeof item === "string" ? item : item?.version)));
  const justStarted = started.filter((v) => !listed.has(v));

  const options = installOptions(installable, [...(Array.isArray(installed) ? installed : []), ...justStarted]);
  const everythingInstalled = allInstalled(options);
  const [chosen, setChosen] = useState(null);
  const version = resolveVersion(chosen, options);

  const unavailable = everythingInstalled
    ? t("install.allInstalled")
    : options.length === 0
      ? catalogError
        ? t("install.catalogUnreachable")
        : t("install.noneAvailable")
      : null;

  const selected = options.find((option) => option.version === version);
  const dead = lifecycleAvailable && selected?.lifecycle?.status === "eol";

  async function install() {
    setPending(true);
    try {
      const response = await INSTALL[runtime](version);
      setStarted((current) => [...current, String(version)]);
      toast.success(
        response.status === 200
          ? t("install.already", { version })
          : t("install.started", { version }),
      );
      setOpen(false);
      router.replace(`${pathname}?version=${encodeURIComponent(version)}`);
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("install.failed")));
    } finally {
      setPending(false);
    }
  }

  return (
    <>
      <ReasonTooltip reason={unavailable ?? (canManage ? null : DENIED)}>
        <Button
          disabled={!canManage || Boolean(unavailable)}
          onClick={() => setOpen(true)}
        >
          <Plus className="size-4" />
          {t("install.action")}
        </Button>
      </ReasonTooltip>

      <FormModal
        open={open}
        onOpenChange={(next) => !pending && setOpen(next)}
        asForm
        onSubmit={(event) => {
          event.preventDefault();
          install();
        }}
        icon={Download}
        title={t("install.title")}
        description={t("install.description")}
        footer={
          <>
            <Button
              type="button"
              variant="outline"
              onClick={() => setOpen(false)}
              disabled={pending}
            >
              {t("versions.confirmCancel")}
            </Button>
            <Button type="submit" disabled={pending || !version}>
              {pending && <Loader2 className="size-4 animate-spin" />}
              {pending ? t("install.installing") : t("install.submit")}
            </Button>
          </>
        }
      >
        <div className="space-y-2">
          <Label htmlFor={`${runtime}-version`}>{t("install.version")}</Label>
          <Select value={version} onValueChange={setChosen}>
            <SelectTrigger id={`${runtime}-version`} className="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {options.map((option) => (
                <SelectItem
                  key={option.version}
                  value={option.version}
                  disabled={option.installed}
                >
                  <span className="flex items-center gap-2">
                    {t("versions.name", { version: option.version })}
                    {option.installed ? (
                      <span className="rounded bg-muted-foreground/15 px-1.5 py-0.5 text-xs font-medium text-muted-foreground">
                        {t("install.installedTag")}
                      </span>
                    ) : (
                      <LifecycleBadge
                        namespace={runtime}
                        lifecycle={option.lifecycle}
                        available={lifecycleAvailable}
                      />
                    )}
                  </span>
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        {dead ? (
          <p className="flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-xs text-destructive">
            <TriangleAlert className="mt-0.5 size-4 shrink-0" />
            {t("install.eolWarning", { version })}
          </p>
        ) : null}
      </FormModal>
    </>
  );
}

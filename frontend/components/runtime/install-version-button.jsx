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
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { FormModal } from "@/components/ui/form-modal";
import { apiMessage } from "@/lib/api/error-message";

// The page is a Server Component, so it can't hand us a function — it names
// the runtime and we pick. Two runtimes, one dialog.
const INSTALL = { php: installPhpVersion, node: installNodeVersion };

/**
 * Install a version the server can actually get.
 *
 * The list comes from the package index, so it is what THIS server can install
 * rather than every version that exists. The request is queued (202) because
 * apt takes minutes and holds a lock — so the message says it is running, not
 * that it is done.
 */
export function InstallVersionButton({
  runtime,
  installable = [],
  canManage,
  lifecycleAvailable = false,
}) {
  const t = useTranslations(runtime);
  const router = useRouter();
  const pathname = usePathname();
  const [open, setOpen] = useState(false);
  const [version, setVersion] = useState(installable[0]?.version ?? "");
  const [pending, setPending] = useState(false);

  // Never hidden. An empty list means the package index offers nothing new
  // right now, which is a fact worth stating — a button that disappears reads
  // as a missing feature, and "where is Install?" is the question it creates.
  const unavailable = installable.length === 0 ? t("install.noneAvailable") : null;

  // Warn before, not after: a dead version installs perfectly well and gets no
  // security fixes, and that is not something to find out later.
  const chosen = installable.find((option) => option.version === version);
  const dead = lifecycleAvailable && chosen?.lifecycle?.status === "eol";

  async function install() {
    setPending(true);
    try {
      const response = await INSTALL[runtime](version);
      toast.success(
        response.status === 200
          ? t("install.already", { version })
          : t("install.started", { version }),
      );
      setOpen(false);
      // Land on the version that was just asked for, rather than leaving the
      // operator on whichever tab they happened to be on and expecting them to
      // go find it. The install takes minutes and now reports its progress on
      // that tab, so this is where the answer to "is it working?" lives.
      //
      // `replace`, not `push`: Back should return to whatever they were
      // looking at before, not step through each version they installed.
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
      <ReasonTooltip reason={unavailable ?? (canManage ? null : t("noPermission"))}>
        <Button
          variant="outline"
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
          <Select value={version} onValueChange={setVersion}>
            <SelectTrigger id={`${runtime}-version`} className="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {installable.map((option) => (
                <SelectItem key={option.version} value={option.version}>
                  <span className="flex items-center gap-2">
                    {t("versions.name", { version: option.version })}
                    <LifecycleBadge
                      namespace={runtime}
                      lifecycle={option.lifecycle}
                      available={lifecycleAvailable}
                    />
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

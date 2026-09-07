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
import { allInstalled, installOptions, resolveVersion } from "@/lib/runtime/install-options";

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
  installed = [],
  canManage,
  lifecycleAvailable = false,
}) {
  const t = useTranslations(runtime);
  const router = useRouter();
  const pathname = usePathname();
  const [open, setOpen] = useState(false);
  const [pending, setPending] = useState(false);

  // The two runtimes disagreed about whether an installed version belongs in
  // this list, so the answer is settled here instead of taken from whichever
  // page opened the dialog. See lib/runtime/install-options.js.
  const options = installOptions(installable, installed);
  const everythingInstalled = allInstalled(options);
  // The raw choice; `version` below is that choice reconciled against what is
  // still on offer, because the list changes underneath a mounted dialog.
  const [chosen, setChosen] = useState(null);
  const version = resolveVersion(chosen, options);

  // Never hidden. An empty list means the package index offers nothing new
  // right now, which is a fact worth stating — a button that disappears reads
  // as a missing feature, and "where is Install?" is the question it creates.
  //
  // "Nothing on offer" and "you already have all of it" are different facts,
  // and the second one used to be reported as the first.
  const unavailable = everythingInstalled
    ? t("install.allInstalled")
    : options.length === 0
      ? t("install.noneAvailable")
      : null;

  // Warn before, not after: a dead version installs perfectly well and gets no
  // security fixes, and that is not something to find out later.
  const selected = options.find((option) => option.version === version);
  const dead = lifecycleAvailable && selected?.lifecycle?.status === "eol";

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
                    {/* Says which of the two reasons this row cannot be picked.
                        Greying it out alone would read as "unavailable", which
                        is the opposite of the truth — you have it. */}
                    {option.installed ? (
                      <span className="rounded bg-muted-foreground/15 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
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

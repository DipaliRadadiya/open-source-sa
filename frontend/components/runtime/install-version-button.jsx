"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Plus, TriangleAlert } from "lucide-react";
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
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
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
  const [open, setOpen] = useState(false);
  const [version, setVersion] = useState(installable[0]?.version ?? "");
  const [pending, setPending] = useState(false);

  if (installable.length === 0) return null;

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
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("install.failed")));
    } finally {
      setPending(false);
    }
  }

  return (
    <>
      <ReasonTooltip reason={canManage ? null : t("noPermission")}>
        <Button variant="outline" disabled={!canManage} onClick={() => setOpen(true)}>
          <Plus className="size-4" />
          {t("install.action")}
        </Button>
      </ReasonTooltip>

      <Dialog open={open} onOpenChange={(next) => !pending && setOpen(next)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t("install.title")}</DialogTitle>
            <DialogDescription>{t("install.description")}</DialogDescription>
          </DialogHeader>

          <div className="space-y-2 py-4">
            <Label htmlFor={`${runtime}-version`}>{t("install.version")}</Label>
            <Select value={version} onValueChange={setVersion}>
              <SelectTrigger id={`${runtime}-version`} className="w-full max-w-xs">
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
            {dead ? (
              <p className="flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-xs text-destructive">
                <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                {t("install.eolWarning", { version })}
              </p>
            ) : null}
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setOpen(false)} disabled={pending}>
              {t("versions.confirmCancel")}
            </Button>
            <Button onClick={install} disabled={pending || !version}>
              {t("install.submit")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}

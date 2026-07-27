"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { RefreshCw } from "lucide-react";
import { syncPermissions } from "@/lib/api/permissions";
import { Button } from "@/components/ui/button";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";

export function SyncPermissionsButton() {
  const t = useTranslations("roles.sync");
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [pending, setPending] = useState(false);

  async function onConfirm() {
    setPending(true);
    try {
      const res = await syncPermissions();
      toast.success(t("success", { count: res.data?.synced ?? 0 }));
      setOpen(false);
      router.refresh();
    } catch (error) {
      toast.error(error.response?.data?.message || t("failed"));
    } finally {
      setPending(false);
    }
  }

  return (
    <>
      <Tooltip>
        <TooltipTrigger asChild>
          <Button variant="outline" onClick={() => setOpen(true)}>
            <RefreshCw className="size-4" />
            {t("button")}
          </Button>
        </TooltipTrigger>
        <TooltipContent className="max-w-56">{t("hint")}</TooltipContent>
      </Tooltip>

      <ConfirmDialog
        open={open}
        onOpenChange={setOpen}
        icon={RefreshCw}
        tone="default"
        title={t("title")}
        description={t("description")}
        cancelLabel={t("cancel")}
        confirmLabel={pending ? t("syncing") : t("confirm")}
        pending={pending}
        onConfirm={onConfirm}
      />
    </>
  );
}

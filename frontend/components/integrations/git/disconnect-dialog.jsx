"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { ExternalLink, Unplug } from "lucide-react";
import { disconnectAccount } from "@/lib/api/git";
import { revokeTokenUrl } from "@/lib/git/provider-links";
import { apiMessage } from "@/lib/api/error-message";
import { useBranding } from "@/components/branding-provider";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";

/**
 * Disconnecting, and the thing every other panel leaves unsaid.
 *
 * Removing the account deletes the panel's copy of the credential. It does not
 * revoke anything: the token keeps working at the provider until it is deleted
 * there. Someone who believes otherwise walks away from a live credential they
 * think is dead, so the dialog says it and links to the page that fixes it.
 */
export function DisconnectDialog({ account, open, onOpenChange }) {
  const t = useTranslations("git.disconnect");
  const { name: brand } = useBranding();
  const router = useRouter();
  const [pending, setPending] = useState(false);

  async function confirm() {
    setPending(true);
    try {
      await disconnectAccount(account.id);
      toast.success(t("done", { label: account.label }));
      router.refresh();
      onOpenChange?.(false);
    } catch (error) {
      toast.error(apiMessage(error, t("failed")));
    } finally {
      setPending(false);
    }
  }

  const revokeUrl = revokeTokenUrl(account?.provider, account?.host);

  return (
    <ConfirmDialog
      open={open}
      onOpenChange={onOpenChange}
      icon={Unplug}
      tone="destructive"
      title={t("title", { label: account?.label ?? "" })}
      description={t("description")}
      cancelLabel={t("cancel")}
      confirmLabel={pending ? t("working") : t("submit")}
      confirmVariant="destructive"
      pending={pending}
      onConfirm={confirm}
    >
      <div className="space-y-2 rounded-lg border bg-muted/40 px-3 py-2.5">
        <p className="text-xs leading-relaxed">
          {t("stillValid", { brand, provider: account?.provider_title ?? "" })}
        </p>
        {revokeUrl ? (
          <a
            href={revokeUrl}
            target="_blank"
            rel="noreferrer noopener"
            className="inline-flex items-center gap-1 text-xs font-medium text-primary underline-offset-4 hover:underline"
          >
            {t("revokeLink", { provider: account?.provider_title ?? "" })}
            <ExternalLink className="size-3" />
          </a>
        ) : null}
      </div>
    </ConfirmDialog>
  );
}

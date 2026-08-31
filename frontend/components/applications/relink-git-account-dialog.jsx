"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Unlink } from "lucide-react";
import { relinkGitAccount } from "@/lib/api/git";
import { apiMessage } from "@/lib/api/error-message";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

/**
 * Point a site that lost its git account at another one.
 *
 * Only the account is asked for. The endpoint takes the repository, branch and
 * mode from the application as it already stands, so repairing a site that
 * merely lost its credential does not make anyone retype an owner/repo they
 * never changed.
 *
 * The server verifies the pairing before storing it — it asks the candidate
 * account to list the repository's branches — so a wrong account comes back as
 * a 422 naming the repository, and the application is left exactly as it was.
 */
export function RelinkGitAccountDialog({ application, accounts = [], open, onOpenChange }) {
  const t = useTranslations("applications.source");
  const router = useRouter();
  const [accountId, setAccountId] = useState("");
  const [pending, setPending] = useState(false);

  async function confirm() {
    setPending(true);
    try {
      await relinkGitAccount(application.id, { git_account_id: Number(accountId) });
      toast.success(t("relink.done"));
      onOpenChange?.(false);
      setAccountId("");
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("relink.failed")));
    } finally {
      setPending(false);
    }
  }

  return (
    <ConfirmDialog
      open={open}
      onOpenChange={(next) => {
        if (!next) setAccountId("");
        onOpenChange?.(next);
      }}
      icon={Unlink}
      tone="warning"
      title={t("relink.title")}
      description={t("relink.description", { repository: application.repository ?? "—" })}
      cancelLabel={t("relink.cancel")}
      confirmLabel={t("relink.submit")}
      confirmDisabled={!accountId}
      pending={pending}
      onConfirm={confirm}
    >
      <Select value={accountId} onValueChange={setAccountId} disabled={pending}>
        <SelectTrigger className="w-full">
          <SelectValue placeholder={t("relink.choose")} />
        </SelectTrigger>
        <SelectContent>
          {accounts.map((account) => (
            <SelectItem key={account.id} value={String(account.id)}>
              {/* The provider matters here: moving to a different one disables
                  deploy-on-push, because a webhook verifies signatures against
                  its own provider's scheme. */}
              {account.label} · {account.provider_title ?? account.provider}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </ConfirmDialog>
  );
}

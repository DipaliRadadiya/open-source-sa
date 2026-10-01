import { useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
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

// Only the account changes; the server keeps repo/branch/mode and 422s an account that cannot list branches.
export function RelinkGitAccountDialog({ application, accounts = [], open, onOpenChange }) {
  const t = useTranslations("applications.source");
  const { refreshAndWait } = useRefresh();
  // Resets target this default, not "": the dialog is not remounted between opens.
  const defaultAccountId = accounts.length === 1 ? String(accounts[0].id) : "";
  const [accountId, setAccountId] = useState(defaultAccountId);
  const [pending, setPending] = useState(false);

  async function confirm() {
    setPending(true);
    try {
      await relinkGitAccount(application.id, { git_account_id: Number(accountId) });
      await refreshAndWait();
      toast.success(t("relink.done"));
      onOpenChange?.(false);
      setAccountId(defaultAccountId);
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
        if (!next) setAccountId(defaultAccountId);
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
              {/* Switching providers disables deploy-on-push: webhooks verify per provider. */}
              {account.label} · {account.provider_title ?? account.provider}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </ConfirmDialog>
  );
}

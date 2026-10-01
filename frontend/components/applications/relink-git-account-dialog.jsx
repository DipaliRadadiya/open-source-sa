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

/**
 * Point a site that lost its git account at another one. Only the account is
 * asked for; the endpoint keeps the existing repository, branch and mode. The
 * server verifies the account can list the repo's branches, so a wrong account
 * is a 422 and nothing changes.
 */
export function RelinkGitAccountDialog({ application, accounts = [], open, onOpenChange }) {
  const t = useTranslations("applications.source");
  const { refreshAndWait } = useRefresh();
  /*
   * Preselected when there is only one account. A named default because the
   * resets below must target it, not "": the dialog is not remounted between
   * opens, so resetting to "" would undo the preselection.
   */
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
              {/* Provider matters: switching providers disables deploy-on-push, since
                  webhooks verify signatures per provider. */}
              {account.label} · {account.provider_title ?? account.provider}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </ConfirmDialog>
  );
}

import { useState, useEffect } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { KeySquare, KeyRound, Plus, Trash2, Loader2 } from "lucide-react";
import { EmptyState } from "@/components/data-table/empty-state";
import { sshKeySchema } from "@/lib/schemas/system-user";
import {
  listSystemUserSshKeys,
  addSystemUserSshKey,
  deleteSystemUserSshKey,
} from "@/lib/api/system-users";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Skeleton } from "@/components/ui/skeleton";
import { FormModal } from "@/components/ui/form-modal";
import { IconTooltip } from "@/components/ui/icon-tooltip";
import {
  Form,
  FormField,
  FormItem,
  FormLabel,
  FormControl,
  FormMessage,
} from "@/components/ui/form";
import { apiMessage } from "@/lib/api/error-message";
import { useRefresh } from "@/hooks/use-refresh";

// `canManage` false = read-only: the list without Add or Remove (listing only
// needs view).
export function SshKeysDialog({ user, open, onOpenChange, canManage = true }) {
  const t = useTranslations("systemUsers");
  const [keys, setKeys] = useState(null); // null = loading
  // Distinct from an empty list: "no keys" and "could not ask" are opposite claims
  // about who can reach the server.
  const [loadFailed, setLoadFailed] = useState(false);
  const [removing, setRemoving] = useState(null);
  const [pending, setPending] = useState(false);
  const { refresh } = useRefresh();

  const form = useForm({
    resolver: zodResolver(sshKeySchema),
    defaultValues: { name: "", public_key: "" },
  });

  function handleOpenChange(next) {
    if (!next) {
      setKeys(null);
      setLoadFailed(false);
      setRemoving(null);
      form.reset();
    }
    onOpenChange?.(next);
  }

  // Deleted elsewhere: nothing can be done to the account here.
  function gone() {
    toast.info(t("toast.alreadyGone", { username: user.username }));
    handleOpenChange(false);
    refresh();
  }

  // True when the list loaded. A 404 means the account itself is gone.
  async function load() {
    try {
      const res = await listSystemUserSshKeys(user.id);
      setKeys(res.data?.ssh_keys ?? []);
      setLoadFailed(false);
      return true;
    } catch (error) {
      if (error?.response?.status === 404) {
        gone();
        return false;
      }
      setKeys([]);
      setLoadFailed(true);
      return false;
    }
  }

  useEffect(() => {
    if (!open || !user) return;
    let active = true;
    listSystemUserSshKeys(user.id)
      .then((res) => {
        if (!active) return;
        setKeys(res.data?.ssh_keys ?? []);
        setLoadFailed(false);
      })
      .catch((error) => {
        if (!active) return;
        if (error?.response?.status === 404) {
          gone();
          return;
        }
        setKeys([]);
        setLoadFailed(true);
      });
    return () => {
      active = false;
    };
    // The id, not the `user` object: the object is a new reference after every list
    // refresh, and refetching would flicker the keys for no new data.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, user?.id]);

  async function onAdd(values) {
    try {
      await addSystemUserSshKey(user.id, values);
      toast.success(t("toast.keyAdded"));
      // Stays open for the next key; the key appearing above and the form emptying
      // confirm it went through.
      form.reset();
      await load();
      document.querySelector("[data-ssh-key-name]")?.focus();
    } catch (error) {
      if (error?.response?.status === 404) return gone();
      handleValidationError(error, form, { fallback: t("toast.keyAddFailed") });
    }
  }

  // One click, no second confirm step; the row shows the removal while it runs.
  async function onRemove(id) {
    setRemoving(id);
    setPending(true);
    try {
      await deleteSystemUserSshKey(user.id, id);
      toast.success(t("toast.keyRemoved"));
      setRemoving(null);
      await load();
    } catch (error) {
      // Removed elsewhere (the key or the account): the goal is met. The reload tells
      // which; a deleted account closes the dialog.
      if (error?.response?.status === 404) {
        setRemoving(null);
        if (await load()) toast.success(t("toast.keyRemoved"));
        return;
      }
      setRemoving(null);
      toast.error(apiMessage(error, t("toast.keyRemoveFailed")));
    } finally {
      setPending(false);
    }
  }

  const isSubmitting = form.formState.isSubmitting;

  return (
    <Form {...form}>
      <FormModal
        open={open}
        onOpenChange={handleOpenChange}
        asForm
        onSubmit={form.handleSubmit(onAdd, () => scrollToFirstError())}
        icon={KeySquare}
        title={`${t("detail.sshKeys")} — ${user?.username ?? ""}`}
        description={t("sshForm.subtitle", { username: user?.username ?? "" })}
        footer={
          <>
            <Button
              type="button"
              variant="outline"
              onClick={() => handleOpenChange(false)}
            >
              {t("close")}
            </Button>
            {canManage ? (
              <Button type="submit" disabled={isSubmitting}>
                {isSubmitting ? (
                  <Loader2 className="size-4 animate-spin" />
                ) : (
                  <Plus className="size-4" />
                )}
                {t("sshForm.submit")}
              </Button>
            ) : null}
          </>
        }
      >
              {keys === null ? (
                <ul className="divide-y rounded-lg border">
                  {[0, 1].map((i) => (
                    <li key={i} className="flex items-center gap-2.5 p-3">
                      <Skeleton className="size-4 shrink-0 rounded" />
                      <div className="flex-1 space-y-1.5">
                        <Skeleton className="h-3.5 w-24" />
                        <Skeleton className="h-3 w-48" />
                      </div>
                    </li>
                  ))}
                </ul>
              ) : loadFailed ? (
                <div className="space-y-2 rounded-lg border border-dashed py-6 text-center">
                  <p className="text-sm text-muted-foreground">{t("sshKeys.loadFailed")}</p>
                  <Button type="button" variant="outline" size="sm" onClick={load}>
                    {t("sshKeys.retryLoad")}
                  </Button>
                </div>
              ) : keys.length === 0 ? (
                <EmptyState compact icon={KeyRound} badge={null} title={t("detail.noSshKeys")} />
              ) : (
                <ul className="divide-y rounded-lg border">
                  {keys.map((key) => (
                    <li
                      key={key.id}
                      className="flex items-start justify-between gap-3 p-3"
                    >
                      <div className="flex min-w-0 items-start gap-2.5">
                        <KeyRound className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                        <div className="min-w-0">
                          <p className="truncate text-sm font-medium">
                            {key.name}
                          </p>
                          <p className="truncate font-mono text-xs text-muted-foreground">
                            {key.fingerprint}
                          </p>
                        </div>
                      </div>
                      {!canManage ? null : (
                        <IconTooltip label={t("sshForm.remove")}>
                          <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-8 shrink-0 text-destructive hover:bg-destructive/10 hover:text-destructive"
                            onClick={() => onRemove(key.id)}
                            disabled={pending}
                            aria-label={t("sshForm.remove")}
                          >
                            {removing === key.id ? (
                              <Loader2 className="size-4 animate-spin" />
                            ) : (
                              <Trash2 className="size-4" />
                            )}
                          </Button>
                        </IconTooltip>
                      )}
                    </li>
                  ))}
                </ul>
              )}

              {canManage ? (
                <div className="space-y-3 rounded-lg border p-3">
                  <p className="text-xs font-semibold text-muted-foreground">
                    {t("sshForm.addHeading")}
                  </p>
                  <FormField
                    control={form.control}
                    name="name"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel required hint={t("sshForm.nameHint")}>{t("sshForm.name")}</FormLabel>
                        <FormControl>
                          <Input
                            data-ssh-key-name
                            placeholder={t("sshForm.namePlaceholder")}
                            autoComplete="off"
                            {...field}
                          />
                        </FormControl>
                        <FormMessage field={t('sshForm.name')} />
                      </FormItem>
                    )}
                  />
                  <FormField
                    control={form.control}
                    name="public_key"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel required hint={t("sshForm.publicKeyHint")}>{t("sshForm.publicKey")}</FormLabel>
                        <FormControl>
                          <Textarea
                            rows={2}
                            // Same size as every other field; see the note in create-system-user-dialog.
                            placeholder={t("sshForm.publicKeyPlaceholder")}
                            className="font-mono"
                            {...field}
                          />
                        </FormControl>
                        <FormMessage field={t('sshForm.publicKey')} />
                      </FormItem>
                    )}
                  />
                </div>
              ) : null}
      </FormModal>
    </Form>
  );
}

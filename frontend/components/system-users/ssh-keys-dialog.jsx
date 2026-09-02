import { useState, useEffect } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { KeySquare, KeyRound, Plus, Trash2, Loader2 } from "lucide-react";
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

export function SshKeysDialog({ user, open, onOpenChange }) {
  const t = useTranslations("systemUsers");
  const [keys, setKeys] = useState(null); // null = loading
  // Distinct from an empty list on purpose. "This account has no keys" and "we
  // could not ask" are opposite claims about who can reach the server, and the
  // dialog used to render the first when it meant the second.
  const [loadFailed, setLoadFailed] = useState(false);
  const [removing, setRemoving] = useState(null);
  const [pending, setPending] = useState(false);

  const form = useForm({
    resolver: zodResolver(sshKeySchema),
    defaultValues: { name: "", public_key: "" },
  });

  async function load() {
    try {
      const res = await listSystemUserSshKeys(user.id);
      setKeys(res.data?.ssh_keys ?? []);
      setLoadFailed(false);
    } catch {
      setKeys([]);
      setLoadFailed(true);
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
      .catch(() => {
        if (!active) return;
        setKeys([]);
        setLoadFailed(true);
      });
    return () => {
      active = false;
    };
    // The id, not the `user` object — the object is a new reference after every
    // refresh of the list behind this dialog, and refetching the keys on each
    // one would flicker the list for no new data.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, user?.id]);

  function handleOpenChange(next) {
    if (!next) {
      setKeys(null);
      setLoadFailed(false);
      setRemoving(null);
      form.reset();
    }
    onOpenChange?.(next);
  }

  async function onAdd(values) {
    try {
      await addSystemUserSshKey(user.id, values);
      toast.success(t("toast.keyAdded"));
      // Closed on success. It used to stay open on the theory that you might
      // add a second key, but the form was already empty and the list already
      // updated, so the dialog just sat there looking like the save had not
      // gone through. handleOpenChange clears the form and the loaded list, so
      // reopening starts clean.
      handleOpenChange(false);
    } catch (error) {
      handleValidationError(error, form);
    }
  }

  async function onRemove(id) {
    setPending(true);
    try {
      await deleteSystemUserSshKey(user.id, id);
      toast.success(t("toast.keyRemoved"));
      setRemoving(null);
      await load();
    } catch (error) {
      toast.error(apiMessage(error, t("toast.failed")));
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
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting ? (
                <Loader2 className="size-4 animate-spin" />
              ) : (
                <Plus className="size-4" />
              )}
              {t("sshForm.submit")}
            </Button>
          </>
        }
      >
        {/* Existing keys */}
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
                <p className="rounded-lg border border-dashed py-6 text-center text-sm text-muted-foreground">
                  {t("detail.noSshKeys")}
                </p>
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
                      {removing === key.id ? (
                        <div className="flex shrink-0 items-center gap-1">
                          <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() => setRemoving(null)}
                            disabled={pending}
                          >
                            {t("cancel")}
                          </Button>
                          <Button
                            type="button"
                            size="sm"
                            variant="destructive"
                            onClick={() => onRemove(key.id)}
                            disabled={pending}
                          >
                            {pending && (
                              <Loader2 className="size-3.5 animate-spin" />
                            )}
                            {t("sshForm.removeConfirm")}
                          </Button>
                        </div>
                      ) : (
                        <IconTooltip label={t("sshForm.remove")}>
                          <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-8 shrink-0 text-muted-foreground hover:text-destructive"
                            onClick={() => setRemoving(key.id)}
                            aria-label={t("sshForm.remove")}
                          >
                            <Trash2 className="size-4" />
                          </Button>
                        </IconTooltip>
                      )}
                    </li>
                  ))}
                </ul>
              )}

              {/* Add a key */}
              <div className="space-y-3 rounded-lg border p-3">
                <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                  {t("sshForm.addHeading")}
                </p>
                <FormField
                  control={form.control}
                  name="name"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel required>{t("sshForm.name")}</FormLabel>
                      <FormControl>
                        <Input
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
                      <FormLabel required>{t("sshForm.publicKey")}</FormLabel>
                      <FormControl>
                        <Textarea
                          rows={2}
                          // Same size as every other field — see the note in
                          // create-system-user-dialog.
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
      </FormModal>
    </Form>
  );
}

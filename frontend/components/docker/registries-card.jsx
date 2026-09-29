"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { KeyRound, Plus, Trash2, PlugZap, Check, X, Clock } from "lucide-react";
import {
  createRegistry,
  deleteRegistry,
  testRegistry,
  updateRegistry,
} from "@/lib/api/docker";
import { registryFormSchema } from "@/lib/schemas/docker";
import { apiMessage } from "@/lib/api/error-message";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";

/**
 * The registry credentials this server can pull private images with.
 *
 * Its own card on the Docker page, beside networks and volumes, because it is the
 * same kind of thing: a server-level object configured once and chosen by many
 * sites.
 *
 * **What this card deliberately cannot do.** It never shows a token, and there is
 * no reveal control to add — the API does not serve one. The password box is
 * always empty, including when editing, and an empty box means "keep what is
 * stored" rather than "clear it". That is why the field's help text says so: an
 * empty input that silently preserves is friendly, and an empty input that
 * silently wipes is a support ticket.
 *
 * There is no rotate button either, for the reason the container-secrets panel has
 * none: rotating a credential the panel does not own means changing it at the
 * registry too, and a panel that claims to have rotated something it only forgot
 * is worse than one that makes you do it yourself.
 */
export function RegistriesCard({ initialRegistries, canManage }) {
  const t = useTranslations("docker.registries");
  const router = useRouter();

  const [pending, setPending] = useState(null);
  const [confirm, setConfirm] = useState(null);
  // The row being edited, or "new" for the create form. One at a time: two open
  // forms on one card is two password boxes, and it stops being obvious which
  // credential an empty one preserves.
  const [editing, setEditing] = useState(null);
  const [form, setForm] = useState({
    name: "",
    registry: "",
    username: "",
    token: "",
  });
  const [errors, setErrors] = useState({});

  async function run(key, action, successMessage) {
    setPending(key);
    try {
      await action();
      toast.success(successMessage);
      // Every sibling on this page is server-rendered, so without the refresh the
      // table keeps showing page-load state for as long as the tab is open.
      router.refresh();
      return true;
    } catch (error) {
      toast.error(apiMessage(error, t("failed")));
      return false;
    } finally {
      setPending(null);
    }
  }

  function open(registry) {
    setEditing(registry?.id ?? "new");
    setErrors({});
    setForm({
      name: registry?.name ?? "",
      registry: registry?.registry ?? "",
      username: registry?.username ?? "",
      // Always empty, never a placeholder standing in for a stored value. A dotted
      // mask would be a length disclosure, and this is the one field where the
      // length is a meaningful hint about the token.
      token: "",
    });
  }

  async function submit(event) {
    event.preventDefault();

    const creating = editing === "new";

    // On edit the token is optional, because omitting it preserves. On create it
    // is required, since a registry row with no token cannot do the one thing it
    // exists for.
    const schema = creating
      ? registryFormSchema
      : registryFormSchema.partial({ token: true });
    const parsed = schema.safeParse(form);

    if (!parsed.success) {
      setErrors(
        Object.fromEntries(
          Object.entries(parsed.error.flatten().fieldErrors).map(([k, v]) => [
            k,
            v?.[0],
          ]),
        ),
      );
      return;
    }

    setErrors({});

    const payload = { ...parsed.data };

    // An empty string is not "clear it" — the API treats an absent key as "keep".
    if (!payload.token) delete payload.token;

    const ok = creating
      ? await run(
          "save",
          () => createRegistry(payload),
          t("created", { name: payload.name }),
        )
      : await run(
          "save",
          () => updateRegistry(editing, payload),
          t("updated", { name: payload.name }),
        );

    if (ok) setEditing(null);
  }

  function statusBadge(registry) {
    if (registry.status === "connected") {
      return (
        <Badge variant="secondary" className="gap-1">
          <Check className="size-3" />
          {registry.status_title}
        </Badge>
      );
    }

    if (registry.status === "failed") {
      return (
        <Badge variant="destructive" className="gap-1">
          <X className="size-3" />
          {registry.status_title}
        </Badge>
      );
    }

    // `never_tested`, and muted rather than a warning colour: the panel not having
    // asked yet is not a fault in the credential.
    return (
      <Badge variant="outline" className="gap-1 text-muted-foreground">
        <Clock className="size-3" />
        {registry.status_title}
      </Badge>
    );
  }

  return (
    <>
      <Card>
        <CardHeader className="flex-row items-center justify-between gap-4 space-y-0">
          <CardTitle className="flex items-center gap-2">
            <KeyRound className="size-4" />
            {t("title")}
          </CardTitle>
          {canManage && editing === null ? (
            <Button type="button" size="sm" onClick={() => open(null)}>
              <Plus className="size-4" />
              {t("add")}
            </Button>
          ) : null}
        </CardHeader>
        <CardContent>
          <p className="mb-4 text-sm text-muted-foreground">{t("hint")}</p>

          {editing !== null ? (
            <form
              className="mb-6 grid gap-4 rounded-md border p-4 sm:grid-cols-2"
              onSubmit={submit}
            >
              <div className="grid gap-2">
                <Label htmlFor="registry-name">{t("fields.name")}</Label>
                <Input
                  id="registry-name"
                  value={form.name}
                  onChange={(event) =>
                    setForm({ ...form, name: event.target.value })
                  }
                  placeholder={t("placeholders.name")}
                />
                {errors.name ? (
                  <p className="text-xs text-destructive">
                    {t("invalid.name")}
                  </p>
                ) : null}
              </div>

              <div className="grid gap-2">
                <Label htmlFor="registry-host">{t("fields.registry")}</Label>
                <Input
                  id="registry-host"
                  value={form.registry}
                  onChange={(event) =>
                    setForm({ ...form, registry: event.target.value })
                  }
                  placeholder={t("placeholders.registry")}
                  className="font-mono text-xs"
                />
                {/* Names the mistake rather than saying "invalid": a namespace here
                    is the common one, and it is the one that would otherwise be
                    accepted and silently never apply. */}
                {errors.registry ? (
                  <p className="text-xs text-destructive">
                    {t("invalid.registry")}
                  </p>
                ) : null}
              </div>

              <div className="grid gap-2">
                <Label htmlFor="registry-username">
                  {t("fields.username")}
                </Label>
                <Input
                  id="registry-username"
                  value={form.username}
                  onChange={(event) =>
                    setForm({ ...form, username: event.target.value })
                  }
                  autoComplete="off"
                />
                {errors.username ? (
                  <p className="text-xs text-destructive">
                    {t("invalid.username")}
                  </p>
                ) : null}
              </div>

              <div className="grid gap-2">
                <Label htmlFor="registry-token">{t("fields.token")}</Label>
                <Input
                  id="registry-token"
                  type="password"
                  value={form.token}
                  onChange={(event) =>
                    setForm({ ...form, token: event.target.value })
                  }
                  autoComplete="new-password"
                  className="font-mono text-xs"
                />
                <p className="text-xs text-muted-foreground">
                  {editing === "new" ? t("tokenHint") : t("tokenHintEdit")}
                </p>
                {errors.token ? (
                  <p className="text-xs text-destructive">
                    {t("invalid.token")}
                  </p>
                ) : null}
              </div>

              <div className="flex items-center gap-2 sm:col-span-2">
                <Button type="submit" size="sm" disabled={pending === "save"}>
                  {t("save")}
                </Button>
                <Button
                  type="button"
                  size="sm"
                  variant="ghost"
                  onClick={() => setEditing(null)}
                >
                  {t("cancel")}
                </Button>
              </div>
            </form>
          ) : null}

          {initialRegistries.length === 0 ? (
            <p className="text-sm text-muted-foreground">{t("empty")}</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t("columns.name")}</TableHead>
                  <TableHead>{t("columns.registry")}</TableHead>
                  <TableHead>{t("columns.username")}</TableHead>
                  <TableHead>{t("columns.status")}</TableHead>
                  <TableHead>{t("columns.usedBy")}</TableHead>
                  <TableHead className="w-32" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {initialRegistries.map((registry) => (
                  <TableRow key={registry.id}>
                    <TableCell>{registry.name}</TableCell>
                    <TableCell className="font-mono text-xs">
                      {registry.registry}
                      {/* Hub's credentials are keyed on the legacy v1 index URL,
                          not on what was typed. Shown, because the alternative is
                          a user comparing their entry to Docker's docs and
                          concluding the panel stored it wrong. */}
                      {registry.is_docker_hub ? (
                        <Badge variant="outline" className="ml-2 font-sans">
                          {t("dockerHub")}
                        </Badge>
                      ) : null}
                    </TableCell>
                    <TableCell className="font-mono text-xs">
                      {registry.username}
                    </TableCell>
                    <TableCell>
                      {statusBadge(registry)}
                      {registry.last_test_error ? (
                        <p className="mt-1 text-xs text-muted-foreground">
                          {t(`errors.${registry.last_test_error}`)}
                        </p>
                      ) : null}
                    </TableCell>
                    <TableCell className="text-sm text-muted-foreground">
                      {registry.applications_count
                        ? t("usedBySites", {
                            count: registry.applications_count,
                          })
                        : t("usedByNone")}
                    </TableCell>
                    <TableCell className="text-right">
                      {canManage ? (
                        <div className="flex justify-end gap-1">
                          <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            disabled={pending === `test-${registry.id}`}
                            onClick={() =>
                              run(
                                `test-${registry.id}`,
                                () => testRegistry(registry.id),
                                t("tested"),
                              )
                            }
                          >
                            <PlugZap className="size-4" />
                            <span className="sr-only">{t("test")}</span>
                          </Button>
                          <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() => open(registry)}
                          >
                            {t("edit")}
                          </Button>
                          <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() => setConfirm(registry)}
                          >
                            <Trash2 className="size-4" />
                            <span className="sr-only">{t("remove")}</span>
                          </Button>
                        </div>
                      ) : null}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      <ConfirmDialog
        open={confirm !== null}
        onOpenChange={(open) => !open && setConfirm(null)}
        title={t("confirm.title", { name: confirm?.name ?? "" })}
        /* Says what happens to the sites, because the answer is not obvious and
           both other answers would be wrong: the sites are NOT deleted, and the
           delete is NOT blocked by them. A credential that cannot be revoked
           while anything uses it is a credential that cannot be revoked. */
        description={
          confirm?.applications_count
            ? t("confirm.bodyInUse", { count: confirm.applications_count })
            : t("confirm.body")
        }
        confirmLabel={t("remove")}
        tone="destructive"
        confirmVariant="destructive"
        onConfirm={async () => {
          const target = confirm;
          setConfirm(null);
          if (target)
            await run(
              `delete-${target.id}`,
              () => deleteRegistry(target.id),
              t("removed", { name: target.name }),
            );
        }}
      />
    </>
  );
}

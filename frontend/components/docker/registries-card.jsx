"use client";

import { useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import {
  KeyRound,
  Plus,
  Trash2,
  PlugZap,
  Check,
  X,
  Clock,
  ExternalLink,
  Info,
} from "lucide-react";
import {
  createRegistry,
  deleteRegistry,
  testRegistry,
  updateRegistry,
} from "@/lib/api/docker";
import { registryFormSchema } from "@/lib/schemas/docker";
import {
  REGISTRY_PROVIDERS,
  registryProvider,
  providerForAddress,
  issuesTemporaryTokens,
} from "@/lib/docker/registry-providers";
import { apiMessage } from "@/lib/api/error-message";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Input } from "@/components/ui/input";
import { Caution } from "@/components/ui/caution";
import { Label } from "@/components/ui/label";
import { Note } from "@/components/ui/note";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
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
  const { refreshAndWait } = useRefresh();

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
  // Which registry the form is giving instructions for; set again on every open.
  const [provider, setProvider] = useState("dockerhub");

  async function run(key, action, successMessage) {
    setPending(key);
    try {
      await action();
      // Before the toast: every sibling on this page is server-rendered, so a toast
      // first leaves the table showing page-load state.
      await refreshAndWait();
      toast.success(successMessage);
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
    // Editing opens on the provider the saved address looks like, so the
    // instructions match the row rather than defaulting to Docker Hub and telling
    // somebody with a GHCR credential to go and read Docker Hub's settings page.
    setProvider(registry ? providerForAddress(registry.registry) : "dockerhub");
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

  /**
   * Choosing a provider fills the address and names the credential it wants.
   *
   * The name is filled too, but only when the user has not typed one — a prefill
   * that overwrites something they wrote is worse than no prefill.
   */
  function chooseProvider(id) {
    const preset = registryProvider(id);
    const previous = registryProvider(provider);

    setProvider(id);
    setErrors({});
    setForm((current) => ({
      ...current,
      // Replaced only when the box is empty or still holds the last preset's
      // value, so a hand-typed self-hosted host survives a mis-click.
      registry:
        current.registry === "" || current.registry === previous.address
          ? preset.address
          : current.registry,
      name:
        current.name === "" || current.name === t(`providers.${provider}.label`)
          ? id === "other"
            ? ""
            : t(`providers.${id}.label`)
          : current.name,
    }));
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
          <CardTitle>
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
          <p className="mb-4 max-w-prose text-sm text-muted-foreground">
            {t("hint")}
          </p>

          {editing !== null ? (
            <form
              noValidate
              className="mb-6 grid gap-4 rounded-md border p-4 sm:grid-cols-2"
              onSubmit={submit}
            >
              {/* The first field, because it decides what the other three mean.
                  Before this the form was three text boxes and a placeholder, and
                  none of the answers are guessable: Docker Hub's host is
                  `docker.io` and not the address in your browser, GHCR needs a
                  CLASSIC token carrying read:packages, and GitLab wants a deploy
                  token whose username is the token's own name. */}
              <div className="grid gap-2 sm:col-span-2">
                <Label htmlFor="registry-provider">
                  {t("fields.provider")}
                </Label>
                <Select value={provider} onValueChange={chooseProvider}>
                  <SelectTrigger id="registry-provider">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {REGISTRY_PROVIDERS.map((option) => (
                      <SelectItem key={option.id} value={option.id}>
                        {t(`providers.${option.id}.label`)}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              {/* Where to get the token, and which scope. The whole reason the
                  picker exists — a scope that is merely plausible produces a
                  credential the registry refuses, which reads as a panel bug. */}
              <div className="sm:col-span-2">
                <Note icon={Info} title={t("howTo.title")}>
                  <ol className="ml-4 list-decimal space-y-1">
                    <li>{t(`providers.${provider}.step1`)}</li>
                    <li>{t(`providers.${provider}.step2`)}</li>
                    <li>{t("howTo.step3")}</li>
                  </ol>
                  {registryProvider(provider).docsUrl ? (
                    <a
                      href={registryProvider(provider).docsUrl}
                      target="_blank"
                      rel="noreferrer noopener"
                      className="mt-2 inline-flex items-center gap-1 font-medium text-primary underline"
                    >
                      {t(`providers.${provider}.linkLabel`)}
                      <ExternalLink className="size-3" />
                    </a>
                  ) : null}
                </Note>
              </div>

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
                  // Read-only for the hosted providers rather than merely
                  // prefilled. Their address is not a preference, and a typo in it
                  // is the worst failure this feature has: Docker ignores a
                  // credential stored under an address it does not recognise, and
                  // the pull then fails exactly as if none existed.
                  readOnly={registryProvider(provider).addressFixed}
                />
                <p className="text-xs text-muted-foreground">
                  {registryProvider(provider).addressFixed
                    ? t("registryHintFixed")
                    : t("registryHint")}
                </p>
                {issuesTemporaryTokens(form.registry) ? (
                  // AWS ECR, Google Artifact Registry and Azure ACR issue
                  // credentials that expire in hours — ECR's in twelve. A token
                  // pasted here authenticates once and then starts failing, and the
                  // panel does not refresh it. Said at the form rather than
                  // discovered on tomorrow's deploy.
                  <Caution size="md">{t("temporaryToken")}</Caution>
                ) : null}
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
                  placeholder={registryProvider(provider).usernamePlaceholder}
                  autoComplete="off"
                />
                {/* Whose username, which is not obvious for two of the four: a
                    GitLab deploy token's username is the token's own name, not the
                    account that made it. */}
                <p className="text-xs text-muted-foreground">
                  {t(`providers.${provider}.usernameHint`)}
                </p>
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
                  placeholder={registryProvider(provider).tokenPlaceholder}
                  autoComplete="new-password"
                  className="font-mono text-xs"
                />
                <p className="text-xs text-muted-foreground">
                  {/* On edit the field is empty and empty means KEEP, so that
                      sentence replaces the provider guidance rather than sitting
                      under it — a box that silently preserves needs saying more
                      than a scope does. */}
                  {editing === "new"
                    ? t(`providers.${provider}.tokenHint`)
                    : t("tokenHintEdit")}
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
            /* Was one sentence, which told somebody the shelf was empty and
               nothing about how to fill it. An empty state is the only
               instruction most people will read, so it carries the two facts
               that decide whether they can proceed: what to have ready, and
               that public images need none of this. */
            <div className="rounded-lg border border-dashed p-6 text-center">
              <KeyRound className="mx-auto size-6 text-muted-foreground" />
              <p className="mt-3 text-sm font-medium">{t("emptyTitle")}</p>
              <p className="mx-auto mt-1 max-w-prose text-sm text-muted-foreground">
                {t("emptyBody")}
              </p>
              {canManage ? (
                <Button
                  type="button"
                  size="sm"
                  className="mt-4"
                  onClick={() => open(null)}
                >
                  <Plus className="size-4" />
                  {t("add")}
                </Button>
              ) : null}
            </div>
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

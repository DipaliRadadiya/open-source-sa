"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import {
  Eye,
  EyeOff,
  Info,
  Loader2,
  RefreshCw,
  ShieldAlert,
  ShieldCheck,
  Webhook,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { updateWebhook } from "@/lib/api/deployment";
import { applicationSchema } from "@/lib/schemas/application";
import { apiMessage } from "@/lib/api/error-message";
import {
  Card,
  CardAction,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { CopyButton } from "@/components/ui/copy-button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

function ReadOnlyField({ label, value, secret = false }) {
  const tc = useTranslations("common");
  const [reveal, setReveal] = useState(false);
  return (
    <div className="space-y-1.5">
      <Label className="text-xs text-muted-foreground">{label}</Label>
      <div className="flex items-center gap-1.5">
        {/* Reveal toggle sits inside the field, matching PasswordInput. */}
        <div className="relative flex-1">
          <Input
            readOnly
            value={value ?? ""}
            type={secret && !reveal ? "password" : "text"}
            className={cn("font-mono text-xs", secret && "pr-10")}
          />
          {secret ? (
            <button
              type="button"
              tabIndex={-1}
              onClick={() => setReveal((v) => !v)}
              aria-label={reveal ? tc("hidePassword") : tc("showPassword")}
              className="absolute inset-y-0 right-0 flex w-10 items-center justify-center rounded-r-lg text-muted-foreground transition-colors hover:text-foreground focus-visible:text-foreground focus-visible:outline-none"
            >
              {reveal ? (
                <EyeOff className="size-4" />
              ) : (
                <Eye className="size-4" />
              )}
            </button>
          ) : null}
        </div>
        <CopyButton value={value ?? ""} className="size-9 shrink-0" />
      </div>
    </div>
  );
}

function Instructions({ label, text, placeholder }) {
  return (
    <div className="space-y-1.5">
      <Label className="text-xs text-muted-foreground">{label}</Label>
      <div className="flex items-start gap-2 rounded-lg border bg-muted/40 px-3 py-2.5 text-xs leading-5 text-muted-foreground">
        <Info className="mt-0.5 size-3.5 shrink-0" />
        <p>{text || placeholder}</p>
      </div>
    </div>
  );
}

/**
 * Deploy on push. First-time setup needs the provider (stored, never sniffed
 * from the request) and — for GitLab — a signing token, so it's a deliberate
 * form. Once configured, the header Switch governs on/off: disabling keeps the
 * URL and secret, so flipping it back on is instant and never invalidates what
 * the user pasted at the provider.
 */
export function WebhookCard({ application, providers, canManage, onChange }) {
  const t = useTranslations("applications.deployment");
  const webhook = application.webhook ?? { enabled: false };
  const enabled = Boolean(webhook.enabled);
  // Disabling retains the URL + secret + provider, so a hook that has ever been
  // set up stays "configured" — that's what the header switch acts on.
  const configured = Boolean(webhook.url || webhook.provider);

  const [providerName, setProviderName] = useState(webhook.provider ?? "");
  const [gitlabToken, setGitlabToken] = useState("");
  const [upgradeOpen, setUpgradeOpen] = useState(false);
  const [rotateOpen, setRotateOpen] = useState(false);
  const [busy, setBusy] = useState(false);

  const selectedProvider = providers.find((p) => p.name === providerName) ?? null;
  const activeProvider = providers.find((p) => p.name === webhook.provider) ?? null;
  const wantsToken = selectedProvider?.secret_source === "either";
  const verifiedBySignature = webhook.verification === "signature";

  async function save(payload, { successKey, failKey }) {
    setBusy(true);
    try {
      const { data } = await updateWebhook(application.id, payload);
      const parsed = applicationSchema.safeParse(data?.application);
      if (parsed.success) onChange(parsed.data);
      toast.success(t(successKey));
      return true;
    } catch (error) {
      toast.error(apiMessage(error, t(failKey)));
      return false;
    } finally {
      setBusy(false);
    }
  }

  // Header switch: off→on re-enables with the stored provider (secret retained);
  // on→off disables. First-ever enable goes through the form below instead.
  async function toggle(next) {
    if (next) {
      await save(
        { enabled: true, provider: webhook.provider },
        { successKey: "webhook.saved", failKey: "webhook.enableFailed" },
      );
    } else {
      await save(
        { enabled: false },
        { successKey: "webhook.disabledDone", failKey: "webhook.disableFailed" },
      );
    }
  }

  async function enable() {
    if (!providerName) return;
    const payload = { enabled: true, provider: providerName };
    if (wantsToken && gitlabToken.trim()) payload.secret = gitlabToken.trim();
    const ok = await save(payload, {
      successKey: "webhook.saved",
      failKey: "webhook.enableFailed",
    });
    if (ok) setGitlabToken("");
  }

  async function rotate() {
    const ok = await save(
      { rotate: true },
      { successKey: "webhook.rotated", failKey: "webhook.rotateFailed" },
    );
    if (ok) setRotateOpen(false);
  }

  async function applyToken() {
    if (!gitlabToken.trim()) return;
    const ok = await save(
      { enabled: true, provider: webhook.provider, secret: gitlabToken.trim() },
      { successKey: "webhook.saved", failKey: "webhook.enableFailed" },
    );
    if (ok) {
      setGitlabToken("");
      setUpgradeOpen(false);
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <Webhook className="size-4 text-primary" />
          {t("webhook.title")}
        </CardTitle>
        <CardDescription>
          {t("webhook.subtitle", { branch: application.branch ?? "main" })}
        </CardDescription>
        {configured ? (
          <CardAction className="flex items-center gap-2">
            {canManage ? (
              <div className="flex items-center gap-2">
                <span className="text-sm text-muted-foreground">
                  {enabled ? t("webhook.on") : t("webhook.off")}
                </span>
                <Switch
                  checked={enabled}
                  onCheckedChange={toggle}
                  disabled={busy}
                  aria-label={t("webhook.title")}
                />
              </div>
            ) : (
              <Badge variant="secondary" className="font-normal">
                {enabled ? t("webhook.on") : t("webhook.off")}
              </Badge>
            )}
          </CardAction>
        ) : null}
      </CardHeader>

      <CardContent className="space-y-4">
        {enabled ? (
          <>
            <div className="flex flex-wrap items-center gap-2">
              {verifiedBySignature ? (
                <Badge
                  variant="secondary"
                  className="gap-1.5 font-normal text-success"
                >
                  <ShieldCheck className="size-3.5" />
                  {t("webhook.verifiedSignature")}
                </Badge>
              ) : (
                <Badge
                  variant="secondary"
                  className="gap-1.5 font-normal text-warning"
                >
                  <ShieldAlert className="size-3.5" />
                  {t("webhook.verifiedToken")}
                </Badge>
              )}
              {activeProvider ? (
                <span className="text-sm text-muted-foreground">
                  {activeProvider.title}
                </span>
              ) : null}
            </div>

            {/* GitLab-only upgrade: swap a plaintext token for a signing token. */}
            {!verifiedBySignature &&
            canManage &&
            activeProvider?.secret_source === "either" ? (
              <div className="space-y-2 rounded-lg border border-warning/40 bg-warning/5 p-3">
                <p className="text-xs leading-5 text-warning">
                  {t("webhook.tokenUpgrade")}
                </p>
                {upgradeOpen ? (
                  <div className="flex flex-wrap items-center gap-2">
                    <Input
                      value={gitlabToken}
                      onChange={(e) => setGitlabToken(e.target.value)}
                      placeholder={t("webhook.gitlabTokenPlaceholder")}
                      autoComplete="off"
                      className="w-full max-w-xs font-mono text-xs"
                    />
                    <Button
                      size="sm"
                      onClick={applyToken}
                      disabled={!gitlabToken.trim() || busy}
                    >
                      {busy ? <Loader2 className="size-4 animate-spin" /> : null}
                      {t("webhook.saveToken")}
                    </Button>
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() => {
                        setUpgradeOpen(false);
                        setGitlabToken("");
                      }}
                    >
                      {t("cancel")}
                    </Button>
                  </div>
                ) : (
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setUpgradeOpen(true)}
                  >
                    {t("webhook.useSigningToken")}
                  </Button>
                )}
              </div>
            ) : null}

            <div className="grid gap-5 lg:grid-cols-2 lg:items-start">
              <div className="space-y-4">
                <ReadOnlyField label={t("webhook.url")} value={webhook.url} />
                {webhook.secret ? (
                  <ReadOnlyField
                    label={t("webhook.secret")}
                    value={webhook.secret}
                    secret
                  />
                ) : null}
                <p className="text-xs text-muted-foreground">
                  {t("webhook.lastDelivered")}:{" "}
                  <span className="font-medium text-foreground">
                    {webhook.last_delivered_at_human ??
                      webhook.last_delivered_at ??
                      t("webhook.noDeliveries")}
                  </span>
                </p>
              </div>

              {activeProvider?.instructions ? (
                <Instructions
                  label={t("webhook.howTo")}
                  text={activeProvider.instructions}
                />
              ) : null}
            </div>

            {canManage ? (
              <div className="border-t pt-4">
                <Button
                  variant="outline"
                  onClick={() => setRotateOpen(true)}
                  disabled={busy}
                >
                  <RefreshCw className="size-4" />
                  {t("webhook.rotate")}
                </Button>
              </div>
            ) : null}
          </>
        ) : configured ? (
          <p className="text-sm text-muted-foreground">
            {t("webhook.offBody", { branch: application.branch ?? "main" })}
          </p>
        ) : !canManage ? (
          <p className="text-sm text-muted-foreground">
            {t("webhook.disabledBody")}
          </p>
        ) : providers.length ? (
          <div className="grid gap-5 lg:grid-cols-2 lg:items-start">
            <div className="space-y-3">
              <p className="text-sm text-muted-foreground">
                {t("webhook.disabledBody")}
              </p>
              <div className="space-y-1.5">
                <Label className="text-sm">{t("webhook.provider")}</Label>
                <Select value={providerName} onValueChange={setProviderName}>
                  <SelectTrigger className="w-full">
                    <SelectValue
                      placeholder={t("webhook.providerPlaceholder")}
                    />
                  </SelectTrigger>
                  <SelectContent>
                    {providers.map((p) => (
                      <SelectItem key={p.name} value={p.name}>
                        {p.title}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              {wantsToken ? (
                <div className="space-y-1.5">
                  <Label htmlFor="gitlab-token" className="text-sm">
                    {t("webhook.gitlabToken")}
                  </Label>
                  <Input
                    id="gitlab-token"
                    value={gitlabToken}
                    onChange={(e) => setGitlabToken(e.target.value)}
                    placeholder={t("webhook.gitlabTokenPlaceholder")}
                    autoComplete="off"
                    className="font-mono text-xs"
                  />
                  <p className="text-xs text-muted-foreground">
                    {t("webhook.gitlabTokenHint")}
                  </p>
                </div>
              ) : null}

              <Button onClick={enable} disabled={!providerName || busy}>
                {busy ? (
                  <Loader2 className="size-4 animate-spin" />
                ) : (
                  <Webhook className="size-4" />
                )}
                {t("webhook.enable")}
              </Button>
            </div>

            <Instructions
              label={t("webhook.howTo")}
              text={selectedProvider?.instructions}
              placeholder={t("webhook.pickProviderHint")}
            />
          </div>
        ) : (
          <p className="text-sm text-destructive">
            {t("webhook.providersUnavailable")}
          </p>
        )}
      </CardContent>

      <ConfirmDialog
        open={rotateOpen}
        onOpenChange={setRotateOpen}
        icon={RefreshCw}
        title={t("webhook.rotateConfirmTitle")}
        description={t("webhook.rotateConfirmBody")}
        cancelLabel={t("cancel")}
        confirmLabel={busy ? t("webhook.rotating") : t("webhook.rotate")}
        pending={busy}
        onConfirm={rotate}
      />
    </Card>
  );
}

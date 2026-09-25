import { useState } from "react";
import { PANEL_CARD } from "@/lib/theme/card-chrome";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import {
  CircleCheck,
  Eye,
  EyeOff,
  Info,
  Loader2,
  RefreshCw,
  ShieldAlert,
  ShieldCheck,
  TriangleAlert,
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
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
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

// The backend's `min:16` on a webhook secret.
const TOKEN_MIN = 16;

function ReadOnlyField({ label, value, hint, secret = false }) {
  const tc = useTranslations("common");
  const [reveal, setReveal] = useState(false);
  return (
    <div className="space-y-1.5">
      <Label className="text-xs text-muted-foreground" hint={hint}>
        {label}
      </Label>
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
  const tc = useTranslations("common");
  const webhook = application.webhook ?? { enabled: false };
  const enabled = Boolean(webhook.enabled);
  /*
   * The PROVIDER is what makes a hook configured, not the URL.
   *
   * Disabling from the switch retains URL, secret and provider, so a hook that
   * has ever been set up still reads as configured — that case is unchanged.
   *
   * Relinking the site's Git account is the case this fixes. The backend
   * deliberately keeps `webhook_identifier` (it is the public half of the
   * delivery address, and minting a new one would gain nothing) while clearing
   * the provider and the secret. `webhook.url` is derived from that identifier,
   * so it survived — and `url ||` made the card believe the hook was still set
   * up. It rendered the on/off switch instead of the setup form, and flipping
   * it posted `{ enabled: true, provider: null }`, which the API rejects with
   * `required_if`. Error toast, every time, with no route back to the form.
   *
   * A URL is an address. It is not configuration.
   */
  const configured = Boolean(webhook.provider);

  /*
   * Preselected when there is nothing to choose.
   *
   * The page narrows this list to the provider the site's Git account belongs
   * to, so it is usually one entry — and a one-item picker asking which
   * provider you use is a question with a single possible answer. It still
   * falls back to the stored value first, which matters when a hook was set up
   * before the account moved.
   */
  const [providerName, setProviderName] = useState(
    webhook.provider ?? (providers.length === 1 ? providers[0].name : ""),
  );
  const [gitlabToken, setGitlabToken] = useState("");
  const [upgradeOpen, setUpgradeOpen] = useState(false);
  const [rotateOpen, setRotateOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  /*
   * Why the panel could not add the hook to the repository itself, from the
   * last save. Only a save's response carries it; after a reload the card
   * still knows from `webhook.registered` that the hook must be pasted, it
   * just no longer knows why.
   */
  const [manualReason, setManualReason] = useState(null);

  const selectedProvider = providers.find((p) => p.name === providerName) ?? null;
  const activeProvider = providers.find((p) => p.name === webhook.provider) ?? null;
  const wantsToken = selectedProvider?.secret_source === "either";
  const verifiedBySignature = webhook.verification === "signature";
  // The API refuses a secret under 16 characters; said before sending, beside
  // the field, rather than as a toast after the round trip.
  const typedToken = gitlabToken.trim();
  const tokenTooShort = typedToken.length > 0 && typedToken.length < TOKEN_MIN;

  async function save(payload, { successKey, failKey }) {
    setBusy(true);
    try {
      const { data } = await updateWebhook(application.id, payload);
      const parsed = applicationSchema.safeParse(data?.application);
      if (parsed.success) onChange(parsed.data);
      const registration = data?.webhook_registration ?? null;
      setManualReason(registration?.status === "manual" ? (registration.message ?? null) : null);
      toast.success(
        registration?.status === "registered" && successKey !== "webhook.rotated"
          ? t("webhook.added")
          : t(successKey),
      );
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
    // The endpoint validates the whole webhook, not just the change: `enabled`
    // is required on every call and `provider` whenever it is true. Sending
    // only `{ rotate: true }` came back 422 "The enabled field is required."
    // Rotating is only offered on a live webhook, so both are known here.
    const ok = await save(
      { enabled: true, provider: webhook.provider, rotate: true },
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
    <Card className={PANEL_CARD}>
      <CardHeader>
        {/* The same mark every other card on this page wears. It was an inline
            icon beside the text here and a tinted square everywhere else, which
            is the "three of five styled differently" tell. */}
        <CardTitle className="flex items-center gap-2.5">
          <span className="flex shrink-0 items-center justify-center text-muted-foreground">
            <Webhook className="size-4" />
          </span>
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
              <Badge variant={enabled ? "success" : "muted"} className="font-normal">
                {enabled ? t("webhook.on") : t("webhook.off")}
              </Badge>
            )}
          </CardAction>
        ) : canManage && providers.length ? (
          /*
           * The same slot, configured or not.
           *
           * Once a hook exists the on/off control is the switch up here; before
           * it exists, Enable was down in the body — so the one control that
           * turns this feature on moved across the card depending on a state
           * the reader cannot see. Top-right in both cases, like Deploy now on
           * the card above.
           *
           * Still disabled with a reason when a provider genuinely has to be
           * chosen first; that picker stays in the body where the choice is.
           */
          <CardAction>
            <ReasonTooltip
              reason={
                busy ? null : !providerName ? tc("chooseAnOption") : wantsToken && tokenTooShort ? t("webhook.tokenTooShort") : null
              }
            >
              <Button onClick={enable} disabled={!providerName || busy || (wantsToken && tokenTooShort)}>
                {busy ? (
                  <Loader2 className="size-4 animate-spin" />
                ) : (
                  <Webhook className="size-4" />
                )}
                {t("webhook.enable")}
              </Button>
            </ReasonTooltip>
          </CardAction>
        ) : null}
      </CardHeader>

      <CardContent className="space-y-4">
        {enabled ? (
          <>
            <div className="flex flex-wrap items-center gap-2">
              {verifiedBySignature ? (
                <Badge variant="success" className="gap-1.5 font-normal">
                  <ShieldCheck className="size-3.5" />
                  {t("webhook.verifiedSignature")}
                </Badge>
              ) : (
                <Badge variant="warning" className="gap-1.5 font-normal">
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
                    <ReasonTooltip
                      reason={busy ? null : !typedToken ? tc("enterAValue") : tokenTooShort ? t("webhook.tokenTooShort") : null}
                    >
                      <Button
                        size="sm"
                        onClick={applyToken}
                        disabled={!typedToken || tokenTooShort || busy}
                      >
                        {busy ? <Loader2 className="size-4 animate-spin" /> : null}
                        {t("webhook.saveToken")}
                      </Button>
                    </ReasonTooltip>
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

            {/* Added to the repository by the panel: nothing to paste, so
                the URL, secret and paste steps would only be instructions for
                a job already done. */}
            {webhook.registered ? (
              <div className="flex items-start gap-2.5 rounded-lg border border-success/30 bg-success/5 p-3 text-sm">
                <CircleCheck className="mt-0.5 size-4 shrink-0 text-success" />
                <div className="space-y-0.5">
                  <p className="font-medium">{t("webhook.added")}</p>
                  <p className="text-muted-foreground">
                    {t("webhook.addedBody", {
                      provider: activeProvider?.title ?? webhook.provider ?? "",
                      branch: application.branch ?? "main",
                    })}
                  </p>
                  <p className="pt-1 text-xs text-muted-foreground">
                    {t("webhook.lastDelivered")}:{" "}
                    <span className="font-medium text-foreground">
                      {webhook.last_delivered_at_human ??
                        webhook.last_delivered_at ??
                        t("webhook.noDeliveries")}
                    </span>
                  </p>
                </div>
              </div>
            ) : (
            <>
            {manualReason ? (
              <div className="flex items-start gap-2.5 rounded-lg border border-warning/40 bg-warning/5 p-3 text-sm">
                <TriangleAlert className="mt-0.5 size-4 shrink-0 text-warning" />
                <p>{manualReason}</p>
              </div>
            ) : null}
            <div className="grid gap-5 lg:grid-cols-2 lg:items-start">
              <div className="space-y-4">
                <ReadOnlyField
                  label={t("webhook.url")}
                  hint={t("webhook.urlHint")}
                  value={webhook.url}
                />
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
            </>
            )}

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
          /*
           * One sentence and one button.
           *
           * This was a two-column wall: the subtitle said "automatically deploy
           * whenever you push to main", the body underneath said the same thing
           * again in longer words, a labelled field stated a provider nobody
           * had been asked to choose, and half the card was setup steps reading
           * "paste the URL below, paste the secret into Secret" — while no URL
           * and no secret existed yet, because neither is created until this
           * button is pressed.
           *
           * The steps are not removed, they are MOVED: they belong to the
           * configured state, next to the URL and secret they refer to.
           */
          <div className="space-y-4">
            <div className="space-y-3">
              <p className="max-w-prose text-sm text-muted-foreground">
                {providers.length === 1
                  ? t("webhook.disabledBodyNamed", { provider: providers[0].title })
                  : t("webhook.disabledBody")}
              </p>
              {/*
               * One provider: state it, do not ask it.
               *
               * The page resolves the provider from the linked account, and
               * failing that from the repository URL — so for a site on
               * github.com, gitlab.com or bitbucket.org this list has exactly
               * one entry. A dropdown with one option is a question with a
               * single possible answer, and it read as though the panel had
               * not worked something out that it plainly had.
               *
               * The picker stays for the case that is genuinely open: a
               * self-hosted host the URL cannot identify.
               */}
              {providers.length === 1 ? null : (
                <div className="space-y-1.5">
                  <Label className="text-sm" hint={t("webhook.providerHint")}>{t("webhook.provider")}</Label>
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
              )}

              {wantsToken ? (
                <div className="space-y-1.5">
                  <Label htmlFor="gitlab-token" className="text-sm" hint={t("webhook.gitlabTokenHint")}>
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
                </div>
              ) : null}

            </div>
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
        description={
          webhook.registered
            ? t("webhook.rotateConfirmBodyRegistered", { provider: activeProvider?.title ?? webhook.provider ?? "" })
            : t("webhook.rotateConfirmBody")
        }
        cancelLabel={t("cancel")}
        confirmLabel={busy ? t("webhook.rotating") : t("webhook.rotate")}
        pending={busy}
        onConfirm={rotate}
      />
    </Card>
  );
}

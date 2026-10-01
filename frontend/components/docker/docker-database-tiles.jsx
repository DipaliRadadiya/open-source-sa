"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Database, Plus } from "lucide-react";
import { createDockerDatabase } from "@/lib/api/docker";
import { apiMessage } from "@/lib/api/error-message";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Caution } from "@/components/ui/caution";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

/**
 * One tile per engine the server can run, and the dialog that starts one.
 *
 * Shared by the Docker page and the application create page, which is the whole
 * reason it is its own file. A database is not an application — `domain` is required
 * for every site here and a vhost is always written, so a database as a "site" would
 * hold a domain nobody types and a certificate no browser can use — but **"create a
 * database" is something people go to the create page to do**, and being
 * architecturally right about the model is not a reason to be wrong about where the
 * control lives.
 *
 * A tile each rather than one form with an engine dropdown: a dropdown hides the
 * answer to "what can this server run", which is the question somebody arrives with.
 */
export function DockerDatabaseTiles({
  engines = [],
  networks = [],
  databases = [],
  className,
}) {
  const t = useTranslations("docker.databases");
  const [engine, setEngine] = useState(null);

  if (engines.length === 0) return null;

  return (
    <div className={className}>
      <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
        {engines.map((engine) => (
          <button
            key={engine.name}
            type="button"
            onClick={() => setEngine(engine)}
            className="flex items-center justify-between gap-2 rounded-lg border bg-muted/30 px-3 py-2.5 text-left transition-colors hover:bg-muted/60"
          >
            <span className="min-w-0">
              <span className="block truncate text-sm font-medium">
                {engine.label}
              </span>
              <span className="block truncate font-mono text-xs text-muted-foreground">
                {t("newest", { version: engine.versions[0] ?? "—" })}
              </span>
            </span>
            <Plus className="size-4 shrink-0 text-muted-foreground" />
          </button>
        ))}
      </div>

      <DockerDatabaseDialog
        engine={engine}
        databases={databases}
        networks={networks}
        open={engine !== null}
        onOpenChange={(open) => !open && setEngine(null)}
      />
    </div>
  );
}

/**
 * The create dialog on its own, driven by whoever opened it.
 *
 * Separate from the tiles because the application create page opens it from a card
 * in the site-type grid — the one grid people actually look in — while the Docker
 * page opens it from a tile. One dialog either way, so a change to what creating a
 * database asks for cannot reach one caller and miss the other.
 */
export function DockerDatabaseDialog({
  engine,
  databases = [],
  networks = [],
  open,
  onOpenChange,
}) {
  const t = useTranslations("docker.databases");
  const router = useRouter();
  const [pending, setPending] = useState(false);
  const [form, setForm] = useState(null);

  // Seeded when the dialog opens, not on every render: the user edits these, so
  // deriving them would overwrite what they typed on the next keystroke.
  useEffect(() => {
    if (!open || !engine) {
      setForm(null);
      return;
    }
    setForm({
      // The newest the catalog offers. A version nobody chose beats an empty
      // select somebody has to answer before they can see what this does.
      version: engine.versions[0] ?? "",
      name: suggestName(engine.name, databases),
      network: "",
    });
    // `databases` is a fresh array every render on a server component's props, so
    // depending on it would reseed the form mid-edit.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, engine?.name]);

  return (
    <ConfirmDialog
      open={open}
      onOpenChange={onOpenChange}
      icon={Database}
      title={engine ? t("createTitle", { engine: engine.label }) : ""}
      description={t("createBody")}
      confirmLabel={t("create")}
      confirmDisabled={!form?.name?.trim() || !form?.version}
      pending={pending}
      onConfirm={async () => {
        if (!engine || !form) return;
        setPending(true);
        try {
          await createDockerDatabase({
            name: form.name.trim(),
            engine: engine.name,
            version: form.version,
            docker_network: form.network || null,
          });
          toast.success(t("created", { name: form.name.trim() }));
          onOpenChange?.(false);
          // Every caller renders its list on the server, so without this the new
          // row only appears on the next navigation.
          router.refresh();
        } catch (error) {
          toast.error(apiMessage(error, t("failed")));
        } finally {
          setPending(false);
        }
      }}
    >
      <div className="space-y-3">
        <div className="space-y-1.5">
          <Label htmlFor="docker-db-name">{t("nameLabel")}</Label>
          <Input
            id="docker-db-name"
            value={form?.name ?? ""}
            onChange={(event) =>
              setForm((current) => ({ ...current, name: event.target.value }))
            }
          />
          <p className="text-xs text-muted-foreground">{t("nameHint")}</p>
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="docker-db-version">{t("versionLabel")}</Label>
          <Select
            value={form?.version ?? ""}
            onValueChange={(version) =>
              setForm((current) => ({ ...current, version }))
            }
          >
            <SelectTrigger id="docker-db-version">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {(engine?.versions ?? []).map((version) => (
                <SelectItem key={version} value={version}>
                  {version}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        {/* Optional, and the copy says what skipping it costs. A database with no
            network is reachable from the server only — a real answer, and the
            wrong one if a container site needs it. */}
        <div className="space-y-1.5">
          <Label htmlFor="docker-db-network">{t("networkLabel")}</Label>
          <Select
            value={form?.network || NO_NETWORK}
            onValueChange={(network) =>
              setForm((current) => ({
                ...current,
                network: network === NO_NETWORK ? "" : network,
              }))
            }
          >
            <SelectTrigger id="docker-db-network">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={NO_NETWORK}>{t("noNetworkOption")}</SelectItem>
              {networks.map((network) => (
                <SelectItem key={network.name} value={network.name}>
                  {network.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <p className="text-xs text-muted-foreground">{t("networkHint")}</p>
        </div>

        <Caution size="md">
          <p>{t("createWarning")}</p>
        </Caution>
      </div>
    </ConfirmDialog>
  );
}

/** Radix reserves `""` for "nothing selected", so "no network" needs a value. */
const NO_NETWORK = "__none__";

/**
 * A name that is free, so the dialog opens with one answer already filled.
 *
 * The engine's own name first — `postgres` — then `postgres-2` upward. Checked
 * against what exists because the API refuses a duplicate, and offering a name that
 * will be rejected is worse than offering none.
 */
function suggestName(engine, databases) {
  const taken = new Set(databases.map((database) => database.name));
  if (!taken.has(engine)) return engine;
  for (let n = 2; n < 100; n += 1) {
    if (!taken.has(`${engine}-${n}`)) return `${engine}-${n}`;
  }
  return "";
}

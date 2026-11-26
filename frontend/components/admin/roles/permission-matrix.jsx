import { useTranslations } from "next-intl";
import { Server, AppWindow, Folder, ChevronRight } from "lucide-react";
import {
  ACCESS_MANAGE,
  ACCESS_NONE,
  ACCESS_VIEW,
} from "@/lib/schemas/role";
import { Checkbox } from "@/components/ui/checkbox";
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible";
import { countAccess } from "@/lib/roles/granted-count";

const LEVEL_ICONS = { server: Server, application: AppWindow };

function levelIcon(level) {
  return LEVEL_ICONS[level] || Folder;
}

export function permKey(level, name) {
  return `${level}:${name}`;
}

function viewOf(access) {
  return access === ACCESS_VIEW || access === ACCESS_MANAGE;
}

function manageOf(access) {
  return access === ACCESS_MANAGE;
}

function withView(access, next) {
  if (next) return access === ACCESS_MANAGE ? ACCESS_MANAGE : ACCESS_VIEW;
  return ACCESS_NONE;
}

function withManage(access, next) {
  return next ? ACCESS_MANAGE : viewOf(access) ? ACCESS_VIEW : ACCESS_NONE;
}

function AccessCheck({ id, checked, onChange, label }) {
  return (
    <label
      htmlFor={id}
      className="inline-flex cursor-pointer items-center gap-1.5 text-xs font-medium text-foreground select-none"
    >
      <Checkbox id={id} checked={checked} onCheckedChange={(c) => onChange(c === true)} />
      {label}
    </label>
  );
}

function AccessToggles({ idBase, view, manage, onView, onManage, labels }) {
  return (
    <div className="flex shrink-0 items-center gap-4">
      <AccessCheck id={`${idBase}-view`} checked={view} onChange={onView} label={labels.view} />
      <AccessCheck
        id={`${idBase}-manage`}
        checked={manage}
        onChange={onManage}
        label={labels.manage}
      />
    </div>
  );
}

function tally(items, predicate) {
  if (items.every(predicate)) return true;
  return items.some(predicate) ? "indeterminate" : false;
}

export function PermissionMatrix({ groups = [], value, onChange }) {
  const t = useTranslations("roles.form");

  const accessFor = (item) => value[permKey(item.level, item.name)] ?? ACCESS_NONE;

  const labels = { view: t("view"), manage: t("manage") };

  const allItems = groups.flatMap((group) => group.permissions);

  function setMany(items, next) {
    const updates = {};
    for (const item of items) updates[permKey(item.level, item.name)] = next(accessFor(item));
    onChange({ ...value, ...updates });
  }

  const permDesc = (name) => {
    const key = `permDesc.${name}`;
    return t.has(key) ? t(key) : "";
  };

  if (!allItems.length) {
    return <p className="text-sm text-muted-foreground">{t("noPermissions")}</p>;
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-muted/40 px-3 py-2">
        <div className="min-w-0">
          <p className="text-sm font-semibold">{t("selectAll")}</p>
          <p className="text-xs text-muted-foreground">
            {t("legendLine", { view: labels.view, manage: labels.manage })}
          </p>
        </div>
        <AccessToggles
          idBase="setall-everything"
          view={tally(allItems, (item) => viewOf(accessFor(item)))}
          manage={tally(allItems, (item) => manageOf(accessFor(item)))}
          onView={(next) => setMany(allItems, (access) => withView(access, next))}
          onManage={(next) => setMany(allItems, (access) => withManage(access, next))}
          labels={labels}
        />
      </div>

      {groups.map((group) => {
        const items = group.permissions;
        if (!items.length) return null;
        const LevelIcon = levelIcon(group.level);
        const sectionKey = `${group.level}|${group.sub_level}`;

        return (
          <Collapsible key={sectionKey} className="group/section overflow-hidden rounded-lg border">
            <div className="flex flex-wrap items-center justify-between gap-3 bg-muted/40 px-3 py-2 group-data-[state=open]/section:border-b">
              <CollapsibleTrigger className="flex min-w-0 items-center gap-2 rounded text-left focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">
                <ChevronRight className="size-4 shrink-0 text-muted-foreground transition-transform group-data-[state=open]/section:rotate-90" aria-hidden />
                <LevelIcon className="size-4 shrink-0 text-muted-foreground" />
                <span className="text-sm font-semibold">{group.sub_level_title || t("generalGroup")}</span>
                <span className="text-xs font-normal text-muted-foreground tabular-nums">
                  {t("groupCount", { ...countAccess(items.map(accessFor)), total: items.length })}
                </span>
              </CollapsibleTrigger>
              <div className="flex items-center gap-2">
                <span className="text-xs font-medium text-muted-foreground">
                  {t("setAll")}
                </span>
                <AccessToggles
                  idBase={`setall-${sectionKey}`}
                  view={tally(items, (item) => viewOf(accessFor(item)))}
                  manage={tally(items, (item) => manageOf(accessFor(item)))}
                  onView={(next) => setMany(items, (access) => withView(access, next))}
                  onManage={(next) => setMany(items, (access) => withManage(access, next))}
                  labels={labels}
                />
              </div>
            </div>

            <CollapsibleContent className="divide-y">
              {items.map((item) => {
                const access = accessFor(item);
                const description = permDesc(item.name);
                return (
                  <div
                    key={permKey(item.level, item.name)}
                    className="flex flex-col gap-2 px-3 py-2.5 transition-colors hover:bg-muted/40 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between sm:gap-3"
                  >
                    <div className="min-w-0">
                      <p className="text-sm leading-tight font-medium">
                        {item.title || item.name}
                      </p>
                      {description ? (
                        <p className="mt-0.5 text-xs leading-snug text-muted-foreground sm:line-clamp-1">
                          {description}
                        </p>
                      ) : null}
                    </div>
                    <AccessToggles
                      idBase={permKey(item.level, item.name)}
                      view={viewOf(access)}
                      manage={manageOf(access)}
                      onView={(next) => setMany([item], (current) => withView(current, next))}
                      onManage={(next) => setMany([item], (current) => withManage(current, next))}
                      labels={labels}
                    />
                  </div>
                );
              })}
            </CollapsibleContent>
          </Collapsible>
        );
      })}
    </div>
  );
}

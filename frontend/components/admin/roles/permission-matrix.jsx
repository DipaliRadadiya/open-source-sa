"use client";

import { useTranslations } from "next-intl";
import { Server, AppWindow, Folder } from "lucide-react";
import { Checkbox } from "@/components/ui/checkbox";

const LEVEL_ICONS = { server: Server, application: AppWindow };

function levelIcon(level) {
  return LEVEL_ICONS[level] || Folder;
}

export function permKey(level, name) {
  return `${level}:${name}`;
}

function groupByLevel(items) {
  const groups = {};
  for (const item of items) {
    (groups[item.level || ""] ??= []).push(item);
  }
  return groups;
}

function formatLevel(level) {
  if (!level) return "";
  return level.charAt(0).toUpperCase() + level.slice(1);
}

// A single access checkbox with a label. `checked` may be `true`, `false`, or
// `"indeterminate"` (group header when only some rows are on).
function AccessCheck({ id, checked, onChange, label }) {
  return (
    <label
      htmlFor={id}
      className="inline-flex cursor-pointer items-center gap-1.5 text-xs font-medium text-foreground select-none"
    >
      <Checkbox
        id={id}
        checked={checked}
        onCheckedChange={(c) => onChange(c === true)}
      />
      {label}
    </label>
  );
}

// Two checkboxes = the access for one feature (or a whole group). Manage
// implies View; clearing View clears Manage.
function AccessToggles({ idBase, view, manage, onView, onManage, labels }) {
  return (
    <div className="flex shrink-0 items-center gap-4">
      <AccessCheck
        id={`${idBase}-view`}
        checked={view}
        onChange={onView}
        label={labels.view}
      />
      <AccessCheck
        id={`${idBase}-manage`}
        checked={manage}
        onChange={onManage}
        label={labels.manage}
      />
    </div>
  );
}

export function PermissionMatrix({ catalog, value, onChange }) {
  const t = useTranslations("roles.form");
  const groups = groupByLevel(catalog);
  const levels = Object.keys(groups);

  const stateFor = (item) =>
    value[permKey(item.level, item.name)] ?? { view: false, manage: false };

  const labels = { view: t("view"), manage: t("manage") };

  function toggleView(item, next) {
    const cur = stateFor(item);
    onChange({
      ...value,
      [permKey(item.level, item.name)]: {
        view: next,
        manage: next ? cur.manage : false,
      },
    });
  }

  function toggleManage(item, next) {
    const cur = stateFor(item);
    onChange({
      ...value,
      [permKey(item.level, item.name)]: {
        manage: next,
        view: next ? true : cur.view,
      },
    });
  }

  function setGroupView(items, next) {
    const updates = {};
    for (const item of items) {
      const cur = stateFor(item);
      updates[permKey(item.level, item.name)] = {
        view: next,
        manage: next ? cur.manage : false,
      };
    }
    onChange({ ...value, ...updates });
  }

  function setGroupManage(items, next) {
    const updates = {};
    for (const item of items) {
      updates[permKey(item.level, item.name)] = {
        manage: next,
        view: next ? true : stateFor(item).view,
      };
    }
    onChange({ ...value, ...updates });
  }

  const permDesc = (name) => {
    const key = `permDesc.${name}`;
    const text = t(key);
    return text === key ? "" : text;
  };

  if (!catalog.length) {
    return <p className="text-sm text-muted-foreground">{t("noPermissions")}</p>;
  }

  return (
    <div className="space-y-4">
      <p className="text-xs text-muted-foreground">{t("legendLine")}</p>

      {levels.map((level) => {
        const items = groups[level];
        const allView = items.every((i) => stateFor(i).view);
        const allManage = items.every((i) => stateFor(i).manage);
        const someView = items.some((i) => stateFor(i).view);
        const someManage = items.some((i) => stateFor(i).manage);
        // Group checkbox shows a dash when only part of the group is on.
        const groupView = allView ? true : someView ? "indeterminate" : false;
        const groupManage = allManage
          ? true
          : someManage
            ? "indeterminate"
            : false;
        const LevelIcon = levelIcon(level);
        return (
          <div
            key={level || "general"}
            className="overflow-hidden rounded-lg border"
          >
            {/* Group header — one neutral band per level */}
            <div className="flex flex-wrap items-center justify-between gap-3 border-b bg-muted/40 px-3 py-2">
              <span className="flex items-center gap-2 text-sm font-semibold">
                <LevelIcon className="size-4 text-muted-foreground" />
                {formatLevel(level) || t("generalGroup")}
              </span>
              <div className="flex items-center gap-2">
                <span className="text-[11px] font-medium text-muted-foreground">
                  {t("setAll")}
                </span>
                <AccessToggles
                  idBase={`setall-${level || "general"}`}
                  view={groupView}
                  manage={groupManage}
                  onView={(next) => setGroupView(items, next)}
                  onManage={(next) => setGroupManage(items, next)}
                  labels={labels}
                />
              </div>
            </div>

            {/* Permission rows — hairline-divided list */}
            <div className="divide-y">
              {items.map((item) => {
                const s = stateFor(item);
                const description = permDesc(item.name);
                return (
                  <div
                    key={permKey(item.level, item.name)}
                    className="flex flex-col gap-2 px-3 py-2.5 transition-colors hover:bg-muted/40 sm:flex-row sm:items-center sm:justify-between sm:gap-3"
                  >
                    <div className="min-w-0">
                      <p className="text-sm font-medium leading-tight">
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
                      view={s.view}
                      manage={s.manage}
                      onView={(next) => toggleView(item, next)}
                      onManage={(next) => toggleManage(item, next)}
                      labels={labels}
                    />
                  </div>
                );
              })}
            </div>
          </div>
        );
      })}
    </div>
  );
}

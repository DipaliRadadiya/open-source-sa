"use client";

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
} from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { TriangleAlert } from "lucide-react";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { trackPush } from "@/lib/browser/navigation-pending";

const UnsavedContext = createContext(null);

export function UnsavedProvider({ children }) {
  const router = useRouter();
  const t = useTranslations("common");
  // A set: one card saving must not clear another's warning.
  const [dirty, setDirty] = useState(() => new Set());
  const [pendingAction, setPendingAction] = useState(null);

  const setSectionDirty = useCallback((id, isDirty) => {
    setDirty((prev) => {
      if (isDirty === prev.has(id)) return prev;
      const next = new Set(prev);
      if (isDirty) next.add(id);
      else next.delete(id);
      return next;
    });
  }, []);

  const hasUnsaved = dirty.size > 0;

  const guardAction = useCallback(
    (action) => {
      if (!hasUnsaved) return false;
      setPendingAction(() => action);
      return true;
    },
    [hasUnsaved],
  );

  const guardNavigation = useCallback(
    (href, afterConfirm) =>
      guardAction(() => {
        afterConfirm?.();
        trackPush();
        router.push(href);
      }),
    [guardAction, router],
  );

  const value = useMemo(
    () => ({ hasUnsaved, setSectionDirty, guardAction, guardNavigation }),
    [guardAction, guardNavigation, hasUnsaved, setSectionDirty],
  );

  // Covers reload and closing the tab, which in-app handlers can't catch.
  useEffect(() => {
    if (!value.hasUnsaved) return;
    const onBeforeUnload = (event) => {
      event.preventDefault();
      event.returnValue = "";
    };
    window.addEventListener("beforeunload", onBeforeUnload);
    return () => window.removeEventListener("beforeunload", onBeforeUnload);
  }, [value.hasUnsaved]);

  return (
    <UnsavedContext.Provider value={value}>
      {children}
      <ConfirmDialog
        open={pendingAction !== null}
        onOpenChange={(open) => !open && setPendingAction(null)}
        icon={TriangleAlert}
        tone="warning"
        confirmVariant="destructive"
        title={t("unsavedTitle")}
        description={t("unsavedDescription")}
        cancelLabel={t("unsavedStay")}
        confirmLabel={t("unsavedLeave")}
        onConfirm={() => {
          const action = pendingAction;
          setPendingAction(null);
          action?.();
        }}
      />
    </UnsavedContext.Provider>
  );
}

export function useUnsaved() {
  return (
    useContext(UnsavedContext) ?? {
      hasUnsaved: false,
      setSectionDirty: () => {},
      guardAction: () => false,
      guardNavigation: () => false,
    }
  );
}

export function useWatchUnsaved(id, isDirty) {
  const { setSectionDirty } = useUnsaved();

  useEffect(() => {
    setSectionDirty(id, isDirty);
    return () => setSectionDirty(id, false);
  }, [id, isDirty, setSectionDirty]);
}

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

/**
 * Knows whether any panel surface has edits that were never saved.
 *
 * Cards and forms save independently, so the surrounding shell cannot infer
 * their state. They register here, and every shell escape route asks this one
 * provider before it navigates or changes the session.
 */
const UnsavedContext = createContext(null);

export function UnsavedProvider({ children }) {
  const router = useRouter();
  const t = useTranslations("common");
  // A set of ids rather than a boolean: two cards can be dirty at once, and one
  // of them saving must not clear the warning for the other.
  const [dirty, setDirty] = useState(() => new Set());
  // One panel-wide confirmation, rather than a dialog mounted beside every
  // sidebar, breadcrumb and tab link that can leave dirty work behind.
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

  /**
   * Hold an action until the reader confirms that unsaved changes can be lost.
   * Returns true only when the caller must prevent its normal click/select.
   */
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
        router.push(href);
      }),
    [guardAction, router],
  );

  const value = useMemo(
    () => ({ hasUnsaved, setSectionDirty, guardAction, guardNavigation }),
    [guardAction, guardNavigation, hasUnsaved, setSectionDirty],
  );

  // Covers the browser's own exits — reload, close, back out of the app — which
  // no in-app handler can intercept.
  useEffect(() => {
    if (!value.hasUnsaved) return;
    const onBeforeUnload = (event) => {
      event.preventDefault();
      // Chrome ignores the string and shows its own wording, but still needs
      // returnValue set for the prompt to appear at all.
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
  // Outside a panel shell there is nothing registered to lose. Degrade to an
  // unguarded action rather than forcing public/auth surfaces to mount this.
  return (
    useContext(UnsavedContext) ?? {
      hasUnsaved: false,
      setSectionDirty: () => {},
      guardAction: () => false,
      guardNavigation: () => false,
    }
  );
}

/** Registers one card's dirty state, and clears it when the card unmounts. */
export function useWatchUnsaved(id, isDirty) {
  const { setSectionDirty } = useUnsaved();

  useEffect(() => {
    setSectionDirty(id, isDirty);
    return () => setSectionDirty(id, false);
  }, [id, isDirty, setSectionDirty]);
}

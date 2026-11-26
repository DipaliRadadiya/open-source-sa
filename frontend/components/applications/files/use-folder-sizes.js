import { useEffect, useRef, useState } from "react";
import { folderSizes } from "@/lib/api/files";
import { folderSizesResponseSchema } from "@/lib/schemas/file";

async function requestSizes(appId, path, refresh, signal) {
  const { data } = await folderSizes(appId, path, { refresh, signal });
  return folderSizesResponseSchema.parse(data);
}

export function useFolderSizes(appId, path, listing, hasFolders) {
  const [state, setState] = useState({ path: null, listing: null, data: null, error: null, refreshing: false });
  const controller = useRef(null);

  useEffect(() => {
    if (!hasFolders) return undefined;
    const ctrl = new AbortController();
    controller.current?.abort();
    controller.current = ctrl;
    requestSizes(appId, path, false, ctrl.signal).then(
      (data) => setState({ path, listing, data, error: null, refreshing: false }),
      (error) => {
        if (ctrl.signal.aborted || error?.code === "ERR_CANCELED") return;
        setState((s) => ({ path, listing, data: s.path === path ? s.data : null, error, refreshing: false }));
      },
    );
    return () => ctrl.abort();
  }, [appId, path, listing, hasFolders]);

  async function measureAgain() {
    const ctrl = new AbortController();
    controller.current?.abort();
    controller.current = ctrl;
    setState((s) => ({ ...s, refreshing: true, error: null }));
    try {
      const data = await requestSizes(appId, path, true, ctrl.signal);
      setState({ path, listing, data, error: null, refreshing: false });
    } catch (error) {
      if (ctrl.signal.aborted || error?.code === "ERR_CANCELED") return;
      setState((s) => ({ ...s, path, listing, error, refreshing: false }));
    }
  }

  const samePath = state.path === path;
  return {
    data: samePath ? state.data : null,
    error: samePath && state.listing === listing ? state.error : null,
    loading: hasFolders && (state.listing !== listing || state.refreshing),
    measureAgain,
  };
}

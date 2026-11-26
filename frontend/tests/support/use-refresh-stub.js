// The refresh never brings new props here, so a component must hold its own saved state.
export function useRefresh() {
  const refreshAndWait = async () => {
    globalThis.__refreshes = (globalThis.__refreshes ?? 0) + 1;
  };
  return { pending: false, refresh() {
      globalThis.__plainRefreshes = (globalThis.__plainRefreshes ?? 0) + 1;
    }, refreshThen: (fn) => fn(), refreshAndWait };
}

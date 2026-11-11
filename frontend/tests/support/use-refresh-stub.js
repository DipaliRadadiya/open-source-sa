// The refresh never brings new props here, so a component must hold its own saved state.
export function useRefresh() {
  const refreshAndWait = async () => {
    globalThis.__refreshes = (globalThis.__refreshes ?? 0) + 1;
  };
  return { pending: false, refresh() {}, refreshThen: (fn) => fn(), refreshAndWait };
}

import { useCallback, useState } from "react";

export function usePendingKeys() {
  const [keys, setKeys] = useState([]);
  const start = useCallback((key) => setKeys((current) => (current.includes(key) ? current : [...current, key])), []);
  const finish = useCallback((key) => setKeys((current) => current.filter((entry) => entry !== key)), []);
  return { pendingKeys: keys, isPending: (key) => keys.includes(key), start, finish };
}

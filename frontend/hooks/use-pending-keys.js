import { useCallback, useState } from "react";

// A set rather than a single slot, so concurrent rows keep their own spinners.
export function usePendingKeys() {
  const [keys, setKeys] = useState([]);
  const start = useCallback((key) => setKeys((current) => (current.includes(key) ? current : [...current, key])), []);
  const finish = useCallback((key) => setKeys((current) => current.filter((entry) => entry !== key)), []);
  return { pendingKeys: keys, isPending: (key) => keys.includes(key), start, finish };
}

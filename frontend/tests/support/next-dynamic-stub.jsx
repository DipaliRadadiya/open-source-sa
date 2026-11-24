// Stands in for next/dynamic: the lazily loaded editor becomes a plain textarea.
export default function dynamic() {
  return function Editor({ filename, value, onChange, readOnly }) {
    return <textarea aria-label={filename} value={value} readOnly={readOnly} onChange={(event) => onChange?.(event.target.value)} />;
  };
}

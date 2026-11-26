const WHOLE_LABEL_MAX = 24;

export function BreakableDomain({ domain, children }) {
  const labels = String(domain ?? "").split(".");
  const last = labels.pop();
  return (
    <>
      {labels.map((label, i) => (
        <span key={i} className={label.length <= WHOLE_LABEL_MAX ? "whitespace-nowrap" : undefined}>
          {label}.<wbr />
        </span>
      ))}
      <span className="whitespace-nowrap">
        {last}
        {children}
      </span>
    </>
  );
}

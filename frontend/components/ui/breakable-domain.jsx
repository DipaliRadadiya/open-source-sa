// A domain that wraps only after a dot: "ux-clone-1124.23-172-1 / 20-111.nip.io" broke
// mid-label (Krishna, 24 Nov). The last label stays joined to whatever follows it (an
// icon), so an icon never starts a line.
export function BreakableDomain({ domain, children }) {
  const labels = String(domain ?? "").split(".");
  const last = labels.pop();
  return (
    <>
      {labels.map((label, i) => (
        <span key={i}>
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

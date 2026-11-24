// A domain that wraps only after a dot: "ux-clone-1124.23-172-1 / 20-111.nip.io" broke
// mid-label (Krishna, 24 Nov). A label short enough for any line is kept whole, so
// "23-172-120-111" never splits at its dashes; only a label too long for one line may.
// The last label stays joined to whatever follows it (an icon), so an icon never starts a line.
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

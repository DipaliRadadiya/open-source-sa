// next/link needs the App Router context; a plain anchor is enough under jsdom.
export default function AppLink({ href, prefetch: _prefetch, children, ...props }) {
  return (
    <a href={typeof href === "string" ? href : "#"} {...props}>
      {children}
    </a>
  );
}

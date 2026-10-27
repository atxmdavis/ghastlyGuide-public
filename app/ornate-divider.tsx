export default function OrnateDivider({ className = "" }: { className?: string }) {
  return <div className={`ornate-divider ${className}`} aria-hidden="true">
    <span className="ornate-divider__rule" />
    <img src="/assets/skull-divider-ornament.webp" alt="" />
    <span className="ornate-divider__rule" />
  </div>;
}

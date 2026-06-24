import { cn } from "@/lib/cn";

/** The Legion mark: a gradient shield glyph + wordmark. */
export function Logo({ className, showText = true }: { className?: string; showText?: boolean }) {
  return (
    <div className={cn("flex items-center gap-2.5", className)}>
      <span className="relative grid h-9 w-9 place-items-center">
        <svg viewBox="0 0 32 32" className="h-9 w-9" aria-hidden>
          <defs>
            <linearGradient id="lg" x1="0" y1="0" x2="1" y2="1">
              <stop offset="0" stopColor="#a78bfa" />
              <stop offset="0.5" stopColor="#6366f1" />
              <stop offset="1" stopColor="#22d3ee" />
            </linearGradient>
          </defs>
          <path
            d="M16 2.5 27 7v9c0 7-4.8 11.4-11 13.5C9.8 27.4 5 23 5 16V7l11-4.5Z"
            fill="url(#lg)"
            opacity="0.95"
          />
          <path d="M12 11v10h8" fill="none" stroke="white" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round" opacity="0.95" />
        </svg>
      </span>
      {showText && (
        <span className="text-[17px] font-semibold tracking-tight">
          Legion<span className="text-faint font-normal"> CBT</span>
        </span>
      )}
    </div>
  );
}

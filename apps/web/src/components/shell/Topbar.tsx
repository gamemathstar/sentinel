"use client";

import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { Search, LogOut } from "lucide-react";
import { getToken, logout } from "@/lib/api";
import { Logo } from "@/components/brand/Logo";
import { ThemeToggle } from "@/components/ui/ThemeToggle";

export function Topbar() {
  const router = useRouter();
  const [authed, setAuthed] = useState(false);
  useEffect(() => setAuthed(!!getToken()), []);

  return (
    <header className="sticky top-0 z-20 flex items-center gap-3 border-b border-line bg-bg/60 px-5 py-3.5 backdrop-blur-xl">
      <div className="lg:hidden">
        <Logo showText={false} />
      </div>

      <label className="glass flex h-10 flex-1 max-w-md items-center gap-2 rounded-xl px-3 text-sm">
        <Search className="h-4 w-4 text-faint" />
        <input
          placeholder="Search assessments, candidates, items…"
          className="w-full bg-transparent outline-none placeholder:text-faint"
        />
        <kbd className="hidden sm:block rounded-md border border-line px-1.5 text-[10px] text-faint">⌘K</kbd>
      </label>

      <div className="ml-auto flex items-center gap-3">
        <span className="hidden sm:flex h-9 items-center gap-2 rounded-full glass px-3 text-xs text-muted">
          <span className="h-1.5 w-1.5 rounded-full bg-emerald-400 shadow-[0_0_8px] shadow-emerald-400" />
          {authed ? "Connected" : "Demo mode"}
        </span>
        <ThemeToggle />
        <div className="flex items-center gap-2.5">
          <div className="grid h-9 w-9 place-items-center rounded-full bg-gradient-to-br from-violet-500 to-sky-500 text-sm font-semibold">
            EO
          </div>
          {authed && (
            <button
              onClick={() => {
                logout();
                router.push("/login");
              }}
              className="grid h-9 w-9 place-items-center rounded-full text-faint hover:text-ink hover:bg-white/5 transition"
              aria-label="Sign out"
            >
              <LogOut className="h-4 w-4" />
            </button>
          )}
        </div>
      </div>
    </header>
  );
}

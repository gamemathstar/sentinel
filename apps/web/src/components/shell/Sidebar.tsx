"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import {
  LayoutDashboard,
  Layers,
  FileCheck2,
  Radio,
  PenLine,
  ShieldAlert,
  BarChart3,
  Award,
  CalendarClock,
  GraduationCap,
} from "lucide-react";
import { Logo } from "@/components/brand/Logo";
import { cn } from "@/lib/cn";

const nav = [
  { href: "/dashboard", label: "Dashboard", icon: LayoutDashboard },
  { href: "/assessments", label: "Assessments", icon: FileCheck2 },
  { href: "/exams", label: "Live Exams", icon: Radio },
  { href: "/scheduling", label: "Scheduling", icon: CalendarClock },
  { href: "/banks", label: "Question Banks", icon: Layers },
  { href: "/grading", label: "Grading", icon: PenLine },
  { href: "/proctoring", label: "Proctoring", icon: ShieldAlert },
  { href: "/analytics", label: "Analytics", icon: BarChart3 },
  { href: "/certificates", label: "Certificates", icon: Award },
];

export function Sidebar() {
  const pathname = usePathname();

  return (
    <aside className="hidden lg:flex w-64 shrink-0 flex-col gap-2 border-r border-line px-4 py-6">
      <Link href="/dashboard" className="px-2 pb-4">
        <Logo />
      </Link>

      <nav className="flex flex-col gap-1">
        {nav.map(({ href, label, icon: Icon }) => {
          const active = pathname === href || pathname.startsWith(href + "/");
          return (
            <Link
              key={href}
              href={href}
              className={cn(
                "group relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition",
                active ? "text-ink" : "text-muted hover:text-ink hover:bg-white/5",
              )}
            >
              {active && (
                <span className="absolute inset-0 rounded-xl bg-gradient-to-r from-violet-500/20 to-transparent ring-1 ring-violet-400/20" />
              )}
              <Icon className="relative h-[18px] w-[18px]" strokeWidth={1.8} />
              <span className="relative font-medium">{label}</span>
            </Link>
          );
        })}
      </nav>

      <div className="mt-auto">
        <div className="glass ring-gradient rounded-2xl p-4">
          <div className="flex items-center gap-2 text-sm font-medium">
            <GraduationCap className="h-4 w-4 text-violet-600 dark:text-violet-300" strokeWidth={1.8} />
            Demo University
          </div>
          <p className="mt-1 text-xs text-faint">National-scale assessment OS</p>
        </div>
      </div>
    </aside>
  );
}

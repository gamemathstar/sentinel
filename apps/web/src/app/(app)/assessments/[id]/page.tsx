"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { CalendarClock, Radio, ShieldAlert, ArrowUpRight, Users, CheckCircle2 } from "lucide-react";
import { GlassCard } from "@/components/ui/GlassCard";
import { monitorAssessment, type MonitorRow } from "@/lib/api";
import { demoMonitor } from "@/lib/demo";

export default function AssessmentOverviewPage() {
  const { id } = useParams<{ id: string }>();
  const base = `/assessments/${id}`;
  const [rows, setRows] = useState<MonitorRow[]>(demoMonitor.sittings as MonitorRow[]);

  useEffect(() => { monitorAssessment(id).then((d) => setRows(d.sittings)).catch(() => {}); }, [id]);

  const writing = rows.filter((r) => r.status === "in_progress").length;
  const submitted = rows.filter((r) => r.status === "submitted" || r.status === "graded").length;
  const flagged = rows.filter((r) => r.risk && r.risk.cheating_probability >= 0.6).length;

  const cards = [
    { href: `${base}/scheduling`, icon: CalendarClock, title: "Scheduling", blurb: "Select cohorts, map them into venued sessions, assign invigilators, release sittings." },
    { href: `${base}/live`, icon: Radio, title: "Live monitor", blurb: "Watch who is writing in real time — progress, time left, add-time, proctoring risk." },
    { href: `${base}/proctoring`, icon: ShieldAlert, title: "Proctoring", blurb: "Review flagged candidates with an explainable risk score and clear/uphold decisions." },
  ];

  return (
    <div className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-3">
        <Stat icon={Users} label="Candidates" value={rows.length} tone="violet" />
        <Stat icon={Radio} label="Writing" value={writing} tone="sky" />
        <Stat icon={CheckCircle2} label="Submitted" value={submitted} tone="emerald" />
      </div>

      <div className="grid gap-4 md:grid-cols-3">
        {cards.map((c) => (
          <Link key={c.href} href={c.href}>
            <GlassCard glow className="group h-full p-5 transition hover:border-line-strong">
              <div className="flex items-center justify-between">
                <c.icon className="h-6 w-6 text-violet-500" />
                <ArrowUpRight className="h-4 w-4 text-faint transition group-hover:text-ink" />
              </div>
              <h3 className="mt-3 font-medium">{c.title}</h3>
              <p className="mt-1 text-sm text-muted">{c.blurb}</p>
              {c.title === "Proctoring" && flagged > 0 && <p className="mt-2 text-xs text-amber-600 dark:text-amber-300">{flagged} flagged for review</p>}
            </GlassCard>
          </Link>
        ))}
      </div>
    </div>
  );
}

function Stat({ icon: Icon, label, value, tone }: { icon: React.ElementType; label: string; value: number; tone: "violet" | "sky" | "emerald" }) {
  const c = { violet: "text-violet-700 dark:text-violet-200", sky: "text-sky-700 dark:text-sky-200", emerald: "text-emerald-700 dark:text-emerald-200" }[tone];
  return (
    <GlassCard className="p-5">
      <div className="flex items-center gap-2 text-sm text-muted"><Icon className="h-4 w-4 text-violet-500" /> {label}</div>
      <div className={`mt-2 text-3xl font-semibold tabular-nums ${c}`}>{value}</div>
    </GlassCard>
  );
}

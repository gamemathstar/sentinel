"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { motion } from "motion/react";
import { X, Clock, ShieldAlert, Lock, Activity, Plus, ExternalLink } from "lucide-react";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { sittingDetail, type SittingDetail } from "@/lib/api";
import { cn } from "@/lib/cn";

const label = (t: string) => t.replace(/_/g, " ");
const fmtTime = (s: number | null) => {
  if (s == null) return "—";
  return `${Math.floor(s / 60)}:${(s % 60).toString().padStart(2, "0")}`;
};

const DEMO_DETAIL = (id: string): SittingDetail => ({
  id, candidate: "Candidate", status: "in_progress", answered: 42, total: 60, remaining_seconds: 1840,
  session: { mode: "ai_only", lockdown_active: true },
  flags: [
    { type: "phone_detected", confidence: 0.95, occurred_at: new Date().toISOString(), source: "server_inference" },
    { type: "face_absent", confidence: 0.8, occurred_at: new Date().toISOString(), source: "client" },
    { type: "tab_switch", confidence: 1, occurred_at: new Date().toISOString(), source: "client" },
  ],
  risk: { id: "risk-demo", cheating_probability: 0.88, suspicion_score: 1.31, status: "auto", timeline: [
    { type: "phone_detected", contribution: 0.76, combined_confidence: 0.95, occurrences: 1 },
    { type: "face_absent", contribution: 0.4, combined_confidence: 0.8, occurrences: 2 },
    { type: "tab_switch", contribution: 0.15, combined_confidence: 1, occurrences: 3 },
  ] },
});

export function CandidateDrawer({ sittingId, onClose, onAddTime }: { sittingId: string; onClose: () => void; onAddTime: () => void }) {
  const [d, setD] = useState<SittingDetail | null>(null);

  useEffect(() => {
    sittingDetail(sittingId).then(setD).catch(() => setD(DEMO_DETAIL(sittingId)));
  }, [sittingId]);

  const pct = d && d.total ? Math.round((d.answered / d.total) * 100) : 0;
  const risk = d?.risk;

  return (
    <div className="fixed inset-0 z-50 flex justify-end">
      <div className="absolute inset-0 bg-black/50 backdrop-blur-sm" onClick={onClose} />
      <motion.aside
        initial={{ x: 40, opacity: 0 }} animate={{ x: 0, opacity: 1 }} transition={{ duration: 0.25, ease: [0.22, 1, 0.36, 1] }}
        className="glass relative z-10 flex h-full w-full max-w-md flex-col overflow-y-auto border-l border-line p-6"
      >
        {!d ? <p className="text-sm text-faint">Loading…</p> : (
          <>
            <div className="flex items-start justify-between">
              <div>
                <h2 className="text-xl font-semibold tracking-tight">{d.candidate ?? "Candidate"}</h2>
                <p className="mt-1 text-xs text-faint capitalize">{d.status.replace("_", " ")}{d.session ? ` · ${d.session.mode.replace("_", " ")}` : ""}</p>
              </div>
              <button onClick={onClose} className="grid h-8 w-8 place-items-center rounded-lg text-faint hover:text-ink hover:bg-white/5"><X className="h-4 w-4" /></button>
            </div>

            {d.session?.lockdown_active && (
              <div className="mt-3 inline-flex items-center gap-1.5 text-xs text-emerald-600 dark:text-emerald-300"><Lock className="h-3.5 w-3.5" /> lockdown active</div>
            )}

            {/* Progress + time */}
            <div className="mt-5 grid grid-cols-2 gap-3">
              <div className="glass rounded-xl p-4">
                <div className="text-xs text-faint">Answered</div>
                <div className="mt-1 text-2xl font-semibold">{d.answered}<span className="text-base text-faint">/{d.total}</span></div>
                <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-white/8"><div className="h-full rounded-full bg-gradient-to-r from-violet-500 to-sky-400" style={{ width: `${pct}%` }} /></div>
              </div>
              <div className="glass rounded-xl p-4">
                <div className="text-xs text-faint">Time left</div>
                <div className="mt-1 flex items-center gap-1.5 font-mono text-2xl font-semibold"><Clock className="h-4 w-4 text-faint" /> {d.status === "in_progress" ? fmtTime(d.remaining_seconds) : "—"}</div>
              </div>
            </div>

            {/* Risk */}
            {risk && (
              <div className="mt-5">
                <div className="flex items-center justify-between">
                  <span className="flex items-center gap-2 text-sm font-medium"><ShieldAlert className="h-4 w-4 text-amber-500 dark:text-amber-300" /> Risk</span>
                  <Badge tone={risk.cheating_probability >= 0.8 ? "rose" : risk.cheating_probability >= 0.6 ? "amber" : "sky"}>{Math.round(risk.cheating_probability * 100)}%</Badge>
                </div>
                <div className="mt-3 space-y-2">
                  {risk.timeline.map((t) => (
                    <div key={t.type} className="glass rounded-lg p-2.5">
                      <div className="flex items-center justify-between text-xs"><span className="font-medium capitalize">{label(t.type)}</span><span className="text-faint">{Math.round(t.contribution * 100)}%</span></div>
                      <div className="mt-1.5 h-1 overflow-hidden rounded-full bg-white/8"><div className="h-full rounded-full bg-gradient-to-r from-violet-500 to-sky-400" style={{ width: `${t.contribution * 100}%` }} /></div>
                    </div>
                  ))}
                </div>
                <Link href={`/proctoring?focus=${risk.id}`} className="mt-3 inline-flex items-center gap-1.5 text-xs text-violet-600 dark:text-violet-300 hover:underline">
                  <ExternalLink className="h-3.5 w-3.5" /> Open in review console
                </Link>
              </div>
            )}

            {/* Flag timeline */}
            <div className="mt-5">
              <p className="flex items-center gap-2 text-sm font-medium"><Activity className="h-4 w-4 text-violet-500 dark:text-violet-300" /> Flag timeline</p>
              {d.flags.length === 0 ? (
                <p className="mt-2 text-xs text-faint">No flags raised.</p>
              ) : (
                <div className="mt-2 space-y-1.5">
                  {d.flags.map((f, i) => (
                    <div key={i} className="flex items-center justify-between rounded-lg border border-line px-3 py-2 text-xs">
                      <span className="font-medium capitalize">{label(f.type)}</span>
                      <span className="text-faint">{Math.round(f.confidence * 100)}% · {f.source.replace("_", " ")}</span>
                    </div>
                  ))}
                </div>
              )}
            </div>

            {(d.status === "in_progress" || d.status === "assigned") && (
              <div className="mt-auto pt-6">
                <Button onClick={onAddTime} className="w-full"><Plus className="h-4 w-4" /> Add time</Button>
              </div>
            )}
          </>
        )}
      </motion.aside>
    </div>
  );
}

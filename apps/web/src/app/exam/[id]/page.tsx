"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { motion, AnimatePresence } from "motion/react";
import { Lock, Clock, Flag, ChevronLeft, ChevronRight, CheckCircle2, ShieldCheck, Camera, Wifi } from "lucide-react";
import { Logo } from "@/components/brand/Logo";
import { Button } from "@/components/ui/Button";
import { resumeSitting, showSitting, recordResponse, submitSitting, type ExamQuestion } from "@/lib/api";
import { cn } from "@/lib/cn";

type Q = { iv?: string; stem: string; options: string[] };

const DEMO: Q[] = [
  { stem: "Which component is volatile memory?", options: ["SSD", "RAM", "ROM", "Hard disk"] },
  { stem: "What does the SQL keyword SELECT do?", options: ["Deletes rows", "Retrieves data", "Creates a table", "Locks a row"] },
  { stem: "A byte consists of how many bits?", options: ["4", "8", "16", "32"] },
  { stem: "Big-O of binary search on a sorted array?", options: ["O(n)", "O(log n)", "O(n log n)", "O(1)"] },
  { stem: "Which is NOT a programming language?", options: ["Python", "Rust", "HTTP", "Go"] },
  { stem: "What is 2 + 2 × 3?", options: ["12", "8", "10", "6"] },
];

export default function ExamRuntime() {
  const { id } = useParams<{ id: string }>();
  const [live, setLive] = useState(false);
  const [questions, setQuestions] = useState<Q[]>(DEMO);
  const [current, setCurrent] = useState(0);
  const [answers, setAnswers] = useState<Record<number, number>>({});
  const [flagged, setFlagged] = useState<Set<number>>(new Set());
  const [deadline, setDeadline] = useState<number | null>(null);
  const [seconds, setSeconds] = useState(45 * 60);
  const [submitted, setSubmitted] = useState(false);
  const [result, setResult] = useState<{ raw_score: number } | null>(null);
  const submittingRef = useRef(false);

  // Resume the real sitting (restores answers + remaining time); fall back to demo.
  useEffect(() => {
    resumeSitting(id)
      .then((st) => {
        setLive(true);
        const qs: Q[] = (st.questions ?? []).map((q: ExamQuestion) => ({
          iv: q.item_version_id, stem: q.stem ?? "", options: q.options.map((o) => o.text ?? ""),
        }));
        if (qs.length) setQuestions(qs);
        setDeadline(st.sitting.server_deadline_epoch);
        if (st.remaining_seconds != null) setSeconds(st.remaining_seconds);
        // Prefill saved answers (append-only — nothing lost on a crash).
        const pre: Record<number, number> = {};
        qs.forEach((q, i) => {
          const a = q.iv ? st.answers?.[q.iv] : undefined;
          if (a?.selected?.length) pre[i] = a.selected[0];
        });
        setAnswers(pre);
        if (st.sitting.status === "submitted" || st.sitting.status === "graded") setSubmitted(true);
      })
      .catch(() => {}); // demo mode
  }, [id]);

  const doSubmit = useCallback(async () => {
    if (submittingRef.current) return;
    submittingRef.current = true;
    if (live) {
      try {
        const r = await submitSitting(id);
        setResult(r.score);
      } catch { /* ignore */ }
    }
    setSubmitted(true);
  }, [id, live]);

  // Countdown — server epoch is authoritative when live.
  useEffect(() => {
    if (submitted) return;
    const t = setInterval(() => {
      if (deadline != null) {
        const rem = Math.max(0, deadline - Math.floor(Date.now() / 1000));
        setSeconds(rem);
        if (rem === 0) doSubmit();
      } else {
        setSeconds((s) => Math.max(0, s - 1));
      }
    }, 1000);
    return () => clearInterval(t);
  }, [submitted, deadline, doSubmit]);

  // Poll for granted extra time / server-side status changes.
  useEffect(() => {
    if (!live || submitted) return;
    const p = setInterval(() => {
      showSitting(id).then((st) => {
        setDeadline(st.sitting.server_deadline_epoch);
        if (st.sitting.status === "submitted" || st.sitting.status === "graded") setSubmitted(true);
      }).catch(() => {});
    }, 15000);
    return () => clearInterval(p);
  }, [live, submitted, id]);

  const mmss = useMemo(() => {
    const m = Math.floor(seconds / 60).toString().padStart(2, "0");
    const s = (seconds % 60).toString().padStart(2, "0");
    return `${m}:${s}`;
  }, [seconds]);

  const answeredCount = Object.keys(answers).length;
  const q = questions[current];

  function select(optIdx: number) {
    setAnswers((a) => ({ ...a, [current]: optIdx }));
    if (live && q?.iv) recordResponse(id, q.iv, { selected: [optIdx] }).catch(() => {});
  }
  function toggleFlag() {
    setFlagged((prev) => { const n = new Set(prev); n.has(current) ? n.delete(current) : n.add(current); return n; });
  }

  if (submitted) return <Submitted answered={answeredCount} total={questions.length} score={result} />;

  return (
    <div className="flex min-h-screen flex-col">
      <header className="sticky top-0 z-20 flex items-center gap-4 border-b border-line bg-bg/70 px-5 py-3 backdrop-blur-xl">
        <Logo showText={false} />
        <div>
          <div className="text-sm font-medium">Examination</div>
          <div className="flex items-center gap-2 text-xs text-faint">
            <Lock className="h-3 w-3 text-emerald-500 dark:text-emerald-400" /> Lockdown
            <span className="text-line">·</span>
            <Camera className="h-3 w-3 text-sky-500 dark:text-sky-400" /> AI proctoring
            <span className="text-line">·</span>
            <Wifi className="h-3 w-3 text-emerald-500 dark:text-emerald-400" /> {live ? "Live" : "Demo"}
          </div>
        </div>
        <div className="ml-auto flex items-center gap-3">
          <div className={cn("flex items-center gap-2 rounded-xl glass px-4 py-2 font-mono text-lg tabular-nums", seconds < 300 && "text-rose-600 dark:text-rose-300")}>
            <Clock className="h-4 w-4" /> {mmss}
          </div>
          <Button onClick={doSubmit} variant="glass" size="sm">Submit exam</Button>
        </div>
      </header>

      <div className="mx-auto grid w-full max-w-6xl flex-1 gap-6 px-5 py-8 lg:grid-cols-[1fr_280px]">
        <div>
          <div className="flex items-center justify-between">
            <span className="text-sm text-muted">Question <span className="font-semibold text-ink">{current + 1}</span> of {questions.length}</span>
            <button onClick={toggleFlag} className={cn("inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs transition",
              flagged.has(current) ? "bg-amber-500/15 text-amber-700 dark:text-amber-200 border border-amber-400/30" : "text-faint hover:text-ink hover:bg-white/5")}>
              <Flag className="h-3.5 w-3.5" /> {flagged.has(current) ? "Flagged" : "Flag for review"}
            </button>
          </div>

          <AnimatePresence mode="wait">
            <motion.div key={current} initial={{ opacity: 0, x: 16 }} animate={{ opacity: 1, x: 0 }} exit={{ opacity: 0, x: -16 }} transition={{ duration: 0.25 }}>
              <h1 className="mt-4 text-2xl font-semibold leading-snug tracking-tight">{q?.stem}</h1>
              <div className="mt-7 space-y-3">
                {q?.options.map((opt, i) => {
                  const selected = answers[current] === i;
                  return (
                    <button key={i} onClick={() => select(i)}
                      className={cn("group flex w-full items-center gap-4 rounded-2xl border px-5 py-4 text-left transition",
                        selected ? "border-violet-400/50 bg-violet-500/10 shadow-[0_10px_40px_-20px] shadow-violet-500/60" : "border-line glass hover:border-line-strong")}>
                      <span className={cn("grid h-8 w-8 shrink-0 place-items-center rounded-lg border text-sm font-semibold transition",
                        selected ? "border-transparent bg-gradient-to-br from-violet-500 to-sky-500 text-white" : "border-line text-muted")}>
                        {String.fromCharCode(65 + i)}
                      </span>
                      <span className={cn("text-[15px]", selected ? "text-ink" : "text-muted group-hover:text-ink")}>{opt}</span>
                    </button>
                  );
                })}
              </div>
            </motion.div>
          </AnimatePresence>

          <div className="mt-8 flex items-center justify-between">
            <Button variant="glass" onClick={() => setCurrent((c) => Math.max(0, c - 1))} disabled={current === 0}><ChevronLeft className="h-4 w-4" /> Previous</Button>
            {current < questions.length - 1 ? (
              <Button onClick={() => setCurrent((c) => c + 1)}>Next <ChevronRight className="h-4 w-4" /></Button>
            ) : (
              <Button onClick={doSubmit}>Review &amp; submit <CheckCircle2 className="h-4 w-4" /></Button>
            )}
          </div>
        </div>

        <aside className="space-y-4">
          <div className="glass ring-gradient rounded-2xl p-5">
            <div className="flex items-center justify-between text-sm"><span className="text-muted">Progress</span><span className="font-semibold">{answeredCount}/{questions.length}</span></div>
            <div className="mt-3 h-2 overflow-hidden rounded-full bg-white/8"><div className="h-full rounded-full bg-gradient-to-r from-violet-500 to-sky-400 transition-all" style={{ width: `${(answeredCount / questions.length) * 100}%` }} /></div>
            <div className="mt-5 grid grid-cols-5 gap-2">
              {questions.map((_, i) => {
                const isAnswered = answers[i] !== undefined;
                return (
                  <button key={i} onClick={() => setCurrent(i)}
                    className={cn("relative grid aspect-square place-items-center rounded-lg text-sm font-medium transition",
                      i === current && "ring-2 ring-violet-400/70",
                      isAnswered ? "bg-gradient-to-br from-violet-500/40 to-sky-500/30 text-ink" : "glass text-muted hover:text-ink")}>
                    {i + 1}
                    {flagged.has(i) && <span className="absolute -right-1 -top-1 h-2.5 w-2.5 rounded-full bg-amber-400" />}
                  </button>
                );
              })}
            </div>
          </div>
          <div className="glass rounded-2xl p-4 text-xs text-faint">
            <div className="flex items-center gap-2 text-emerald-600 dark:text-emerald-300"><ShieldCheck className="h-4 w-4" /> Server-authoritative timer</div>
            <p className="mt-2 leading-relaxed">Answers save as you go (append-only). The deadline lives on the server — a reconnect restores your work and remaining time, and granted extra time appears automatically.</p>
          </div>
        </aside>
      </div>
    </div>
  );
}

function Submitted({ answered, total, score }: { answered: number; total: number; score: { raw_score: number } | null }) {
  return (
    <div className="grid min-h-screen place-items-center px-6">
      <motion.div initial={{ opacity: 0, scale: 0.96 }} animate={{ opacity: 1, scale: 1 }} transition={{ duration: 0.5 }} className="glass ring-gradient w-full max-w-md rounded-2xl p-8 text-center">
        <div className="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-gradient-to-br from-emerald-500/30 to-transparent text-emerald-500 dark:text-emerald-300"><CheckCircle2 className="h-8 w-8" /></div>
        <h1 className="mt-5 text-2xl font-semibold tracking-tight">Exam submitted</h1>
        <p className="mt-2 text-sm text-muted">You answered {answered} of {total} questions. Objective items are scored against the vault now; your result notification will follow.</p>
        {score && <p className="mt-3 text-sm">Provisional objective score: <span className="font-semibold text-emerald-600 dark:text-emerald-300">{score.raw_score}</span></p>}
        <Link href="/dashboard" className="mt-6 inline-block"><Button>Back to console</Button></Link>
      </motion.div>
    </div>
  );
}

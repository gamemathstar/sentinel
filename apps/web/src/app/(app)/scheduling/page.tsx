"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { motion } from "motion/react";
import {
  CalendarClock, ChevronDown, Wand2, Plus, Building2, Users, MapPin, Clock, ShieldCheck, Rocket, X, Check, UserCog, ListChecks,
} from "lucide-react";
import { GlassCard } from "@/components/ui/GlassCard";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import {
  listAssessments, listOrgNodes, listVenues, createVenue, previewSelection, listSessions,
  createSession, autoMap, getRoster, releaseSchedule, listUsers, assignSessionInvigilators, ApiError,
  type AssessmentRow, type OrgNode, type Venue, type Selection, type SelectionSummary,
  type SessionsView, type ExamSession, type RosterRow, type AutoMapResult, type AutoMapVenue, type StaffUser,
} from "@/lib/api";
import {
  demoAssessments, demoFaculties, demoLevels, demoVenues, demoSelectionSummary, demoSessions, demoRoster, demoUsers,
} from "@/lib/demo";
import { cn } from "@/lib/cn";

type Tab = "automap" | "sessions" | "roster" | "venues";
const STRUCTURAL = ["faculty", "department", "programme"];
const fmt = (iso: string) => new Date(iso).toLocaleString([], { month: "short", day: "numeric", hour: "2-digit", minute: "2-digit" });

export default function SchedulingPage() {
  const [assessments, setAssessments] = useState<AssessmentRow[]>(
    demoAssessments.map((a) => ({ id: a.id, title: a.title, kind: a.kind, status: a.status })),
  );
  const [assessmentId, setAssessmentId] = useState(demoAssessments[0].id);
  const [tab, setTab] = useState<Tab>("automap");
  const [nodes, setNodes] = useState<OrgNode[]>(demoFaculties as OrgNode[]);
  const [venues, setVenues] = useState<Venue[]>(demoVenues as Venue[]);
  const [sessions, setSessions] = useState<SessionsView>(demoSessions as SessionsView);
  const [roster, setRoster] = useState<RosterRow[]>(demoRoster as RosterRow[]);
  const [banner, setBanner] = useState<string | null>(null);

  useEffect(() => {
    listAssessments().then((r) => { if (r.data.length) { setAssessments(r.data); setAssessmentId((id) => r.data.some((a) => a.id === id) ? id : r.data[0].id); } }).catch(() => {});
    listOrgNodes().then((r) => { const s = r.filter((n) => STRUCTURAL.includes(n.type)); if (s.length) setNodes(s); }).catch(() => {});
    listVenues().then((v) => setVenues(v)).catch(() => {});
  }, []);

  const loadSessions = useCallback(() => { listSessions(assessmentId).then(setSessions).catch(() => {}); }, [assessmentId]);
  const loadRoster = useCallback(() => { getRoster(assessmentId).then(setRoster).catch(() => {}); }, [assessmentId]);
  useEffect(() => { loadSessions(); loadRoster(); }, [loadSessions, loadRoster]);

  async function release() {
    setBanner(null);
    try {
      const r = await releaseSchedule(assessmentId);
      setBanner(`Released ${r.released} candidate(s) into live sittings.`);
      loadSessions(); loadRoster();
    } catch (e) {
      setBanner(e instanceof ApiError ? e.message : "Release runs against live data — sign in.");
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="flex items-center gap-2.5 text-3xl font-semibold tracking-tight"><CalendarClock className="h-7 w-7 text-violet-500" /> Scheduling</h1>
          <p className="mt-1 text-sm text-muted">Select cohorts by faculty, department, programme or level — then map them into timed, venued sessions.</p>
        </div>
        <div className="flex items-center gap-3">
          <div className="relative">
            <select value={assessmentId} onChange={(e) => setAssessmentId(e.target.value)} className="h-10 appearance-none rounded-xl border border-line glass pl-4 pr-10 text-sm outline-none focus:border-violet-400/50">
              {assessments.map((a) => <option key={a.id} value={a.id}>{a.title}</option>)}
            </select>
            <ChevronDown className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-faint" />
          </div>
          <Button onClick={release}><Rocket className="h-4 w-4" /> Release to sittings</Button>
        </div>
      </div>

      {banner && <p className="text-xs text-emerald-600 dark:text-emerald-300">{banner}</p>}

      <div className="flex gap-1 rounded-xl glass p-1 text-sm">
        {([["automap", "Auto-map", Wand2], ["sessions", "Sessions", Clock], ["roster", "Roster", Users], ["venues", "Venues", Building2]] as const).map(([id, label, Icon]) => (
          <button key={id} onClick={() => setTab(id)} className={cn("flex flex-1 items-center justify-center gap-2 rounded-lg px-3 py-2 transition", tab === id ? "bg-violet-500/15 text-violet-700 dark:text-violet-200" : "text-muted hover:text-ink")}>
            <Icon className="h-4 w-4" /> {label}
          </button>
        ))}
      </div>

      {tab === "automap" && <AutoMapTab assessmentId={assessmentId} nodes={nodes} venues={venues} onMapped={() => { loadSessions(); loadRoster(); setTab("sessions"); }} />}
      {tab === "sessions" && <SessionsTab view={sessions} assessmentId={assessmentId} venues={venues} roster={roster} onChange={() => { loadSessions(); loadRoster(); }} />}
      {tab === "roster" && <RosterTab rows={roster} />}
      {tab === "venues" && <VenuesTab venues={venues} onChange={() => listVenues().then(setVenues).catch(() => {})} />}
    </div>
  );
}

/* ── Auto-map: selection → venues → start-times → distribute ── */

function AutoMapTab({ assessmentId, nodes, venues, onMapped }: { assessmentId: string; nodes: OrgNode[]; venues: Venue[]; onMapped: () => void }) {
  const [scope, setScope] = useState<"all" | "nodes">("nodes");
  const [picked, setPicked] = useState<string[]>([]);
  const [levels, setLevels] = useState<string[]>([]);
  const [summary, setSummary] = useState<SelectionSummary | null>(null);
  const [vsel, setVsel] = useState<Record<string, number | undefined>>({});
  const [times, setTimes] = useState<string[]>([""]);
  const [duration, setDuration] = useState(60);
  const [result, setResult] = useState<AutoMapResult | null>(null);
  const [err, setErr] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const selection: Selection = useMemo(() => ({ scope, org_node_ids: scope === "nodes" ? picked : undefined, levels: levels.length ? levels : undefined }), [scope, picked, levels]);

  const preview = useCallback(() => {
    previewSelection(selection).then(setSummary).catch(() => setSummary(demoSelectionSummary));
  }, [selection]);
  useEffect(() => { preview(); }, [preview]);

  const chosenVenues: AutoMapVenue[] = Object.entries(vsel).map(([venue_id, capacity]) => ({ venue_id, capacity }));
  const activeVenues = Object.keys(vsel);
  const totalCapacity = times.filter(Boolean).length * activeVenues.reduce((sum, id) => sum + (vsel[id] ?? venues.find((v) => v.id === id)?.capacity ?? 0), 0);

  async function run() {
    setErr(null); setBusy(true); setResult(null);
    try {
      const r = await autoMap(assessmentId, {
        selection,
        venues: chosenVenues,
        start_times: times.filter(Boolean).map((t) => new Date(t).toISOString()),
        duration_minutes: duration,
      });
      setResult(r);
      onMapped();
    } catch (e) {
      setErr(e instanceof ApiError ? e.message : "Auto-map runs against live data — sign in, or use it as a preview here.");
    } finally { setBusy(false); }
  }

  const byType = (t: string) => nodes.filter((n) => n.type === t);

  return (
    <div className="grid gap-6 lg:grid-cols-2">
      {/* Step 1 — selection */}
      <GlassCard className="p-5">
        <h2 className="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-faint"><span className="grid h-5 w-5 place-items-center rounded-full bg-violet-500/15 text-[11px] text-violet-600 dark:text-violet-300">1</span> Who sits the exam</h2>

        <div className="mt-3 flex gap-2">
          {(["nodes", "all"] as const).map((s) => (
            <button key={s} onClick={() => setScope(s)} className={cn("rounded-lg border px-3 py-1.5 text-xs transition", scope === s ? "border-violet-400/50 bg-violet-500/10 text-ink" : "border-line glass text-muted hover:text-ink")}>
              {s === "all" ? "All students" : "By faculty / dept / programme"}
            </button>
          ))}
        </div>

        {scope === "nodes" && (
          <div className="mt-4 space-y-3">
            {STRUCTURAL.map((type) => byType(type).length > 0 && (
              <div key={type}>
                <p className="mb-1.5 text-[11px] uppercase tracking-wide text-faint capitalize">{type}</p>
                <div className="flex flex-wrap gap-1.5">
                  {byType(type).map((n) => {
                    const on = picked.includes(n.id);
                    return (
                      <button key={n.id} onClick={() => setPicked((p) => on ? p.filter((x) => x !== n.id) : [...p, n.id])} className={cn("rounded-full border px-3 py-1 text-xs transition", on ? "border-violet-400/50 bg-violet-500/15 text-violet-700 dark:text-violet-200" : "border-line glass text-muted hover:text-ink")}>
                        {on && <Check className="mr-1 inline h-3 w-3" />}{n.name}
                      </button>
                    );
                  })}
                </div>
              </div>
            ))}
          </div>
        )}

        <div className="mt-4">
          <p className="mb-1.5 text-[11px] uppercase tracking-wide text-faint">Levels (optional)</p>
          <div className="flex flex-wrap gap-1.5">
            {demoLevels.map((l) => {
              const on = levels.includes(l);
              return (
                <button key={l} onClick={() => setLevels((p) => on ? p.filter((x) => x !== l) : [...p, l])} className={cn("rounded-full border px-3 py-1 text-xs transition", on ? "border-sky-400/50 bg-sky-500/15 text-sky-700 dark:text-sky-200" : "border-line glass text-muted hover:text-ink")}>
                  {l} level
                </button>
              );
            })}
          </div>
        </div>

        {summary && (
          <div className="mt-4 rounded-xl border border-line p-3">
            <div className="flex items-center justify-between"><span className="text-sm font-medium">{summary.total} students selected</span><Badge tone="violet">{summary.groups.length} cohorts</Badge></div>
            <div className="mt-2 space-y-1">
              {summary.groups.map((g, i) => (
                <div key={i} className="flex items-center justify-between text-xs text-muted"><span>{g.programme} · {g.level}L</span><span className="tabular-nums">{g.count}</span></div>
              ))}
            </div>
          </div>
        )}
      </GlassCard>

      {/* Step 2 — venues + times */}
      <GlassCard className="p-5">
        <h2 className="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-faint"><span className="grid h-5 w-5 place-items-center rounded-full bg-violet-500/15 text-[11px] text-violet-600 dark:text-violet-300">2</span> Venues &amp; sessions</h2>

        <p className="mt-3 mb-1.5 text-[11px] uppercase tracking-wide text-faint">Venues (set capacity per session)</p>
        <div className="space-y-1.5">
          {venues.map((v) => {
            const on = v.id in vsel;
            return (
              <div key={v.id} className={cn("flex items-center gap-3 rounded-lg border px-3 py-2 transition", on ? "border-violet-400/40 bg-violet-500/5" : "border-line")}>
                <button onClick={() => setVsel((s) => { const n = { ...s }; if (on) delete n[v.id]; else n[v.id] = v.capacity; return n; })} className={cn("grid h-5 w-5 place-items-center rounded border", on ? "border-violet-400 bg-violet-500 text-white" : "border-line")}>{on && <Check className="h-3.5 w-3.5" />}</button>
                <div className="flex-1"><span className="text-sm">{v.name}</span> <span className="text-xs text-faint">· {v.capacity} seats</span></div>
                {on && <input type="number" min={1} value={vsel[v.id] ?? v.capacity} onChange={(e) => setVsel((s) => ({ ...s, [v.id]: Math.max(1, +e.target.value) }))} className="h-8 w-20 rounded-lg border border-line bg-black/[0.02] dark:bg-white/[0.03] px-2 text-sm outline-none focus:border-violet-400/50" />}
              </div>
            );
          })}
        </div>

        <p className="mt-4 mb-1.5 text-[11px] uppercase tracking-wide text-faint">Start times (one session per venue × time)</p>
        <div className="space-y-1.5">
          {times.map((t, i) => (
            <div key={i} className="flex items-center gap-2">
              <input type="datetime-local" value={t} onChange={(e) => setTimes((ts) => ts.map((x, j) => j === i ? e.target.value : x))} className="h-9 flex-1 rounded-lg border border-line bg-black/[0.02] dark:bg-white/[0.03] px-3 text-sm outline-none focus:border-violet-400/50" />
              {times.length > 1 && <button onClick={() => setTimes((ts) => ts.filter((_, j) => j !== i))} className="grid h-8 w-8 place-items-center rounded-lg text-faint hover:text-rose-500"><X className="h-4 w-4" /></button>}
            </div>
          ))}
          <button onClick={() => setTimes((ts) => [...ts, ""])} className="inline-flex items-center gap-1 text-xs text-violet-600 dark:text-violet-300 hover:underline"><Plus className="h-3.5 w-3.5" /> Add start time</button>
        </div>

        <div className="mt-4 flex items-center gap-3">
          <label className="text-xs text-faint">Duration</label>
          <input type="number" min={1} value={duration} onChange={(e) => setDuration(Math.max(1, +e.target.value))} className="h-9 w-24 rounded-lg border border-line bg-black/[0.02] dark:bg-white/[0.03] px-3 text-sm outline-none focus:border-violet-400/50" />
          <span className="text-xs text-faint">min</span>
        </div>

        <div className="mt-4 rounded-xl border border-line p-3 text-xs">
          <div className="flex justify-between"><span className="text-faint">Sessions to create</span><span className="tabular-nums">{times.filter(Boolean).length * activeVenues.length}</span></div>
          <div className="mt-1 flex justify-between"><span className="text-faint">Total capacity</span><span className={cn("tabular-nums", summary && totalCapacity < summary.total ? "text-amber-600 dark:text-amber-300" : "text-emerald-600 dark:text-emerald-300")}>{totalCapacity}{summary && ` / ${summary.total} needed`}</span></div>
        </div>

        {err && <p className="mt-3 text-xs text-amber-600 dark:text-amber-300">{err}</p>}
        <Button onClick={run} disabled={busy || activeVenues.length === 0 || times.filter(Boolean).length === 0} className="mt-4 w-full">
          <Wand2 className="h-4 w-4" /> {busy ? "Mapping…" : "Auto-map candidates"}
        </Button>

        {result && (
          <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} className="mt-4 rounded-xl border border-emerald-400/30 bg-emerald-500/5 p-3 text-xs">
            <p className="font-medium text-emerald-700 dark:text-emerald-200">{result.scheduled} scheduled across {result.sessions_created} sessions{result.unscheduled > 0 && ` · ${result.unscheduled} unplaced (add capacity)`}</p>
            <div className="mt-2 space-y-1">
              {result.per_session.map((s) => (
                <div key={s.session_id} className="flex justify-between text-muted"><span>{s.name}</span><span className="tabular-nums">{s.seated}/{s.capacity}</span></div>
              ))}
            </div>
          </motion.div>
        )}
      </GlassCard>
    </div>
  );
}

/* ── Sessions list + manual create + invigilators ── */

function SessionsTab({ view, assessmentId, venues, roster, onChange }: { view: SessionsView; assessmentId: string; venues: Venue[]; roster: RosterRow[]; onChange: () => void }) {
  const [adding, setAdding] = useState(false);
  const [invigSession, setInvigSession] = useState<ExamSession | null>(null);
  const [rosterSession, setRosterSession] = useState<ExamSession | null>(null);
  return (
    <div className="space-y-4">
      <div className="flex justify-end"><Button variant="glass" onClick={() => setAdding((a) => !a)}><Plus className="h-4 w-4" /> New session</Button></div>
      {adding && <NewSessionForm assessmentId={assessmentId} venues={venues} onDone={() => { setAdding(false); onChange(); }} />}
      {view.sessions.length === 0 && <p className="text-center text-sm text-faint">No sessions yet — auto-map a selection or add one manually.</p>}
      <div className="grid gap-4 md:grid-cols-2">
        {view.sessions.map((s) => {
          const pct = s.capacity ? Math.round((s.seated / s.capacity) * 100) : 0;
          return (
            <GlassCard key={s.id} className="p-5">
              <div className="flex items-start justify-between">
                <div>
                  <h3 className="font-medium">{s.name ?? "Session"}</h3>
                  <p className="mt-0.5 flex items-center gap-1.5 text-xs text-faint"><MapPin className="h-3 w-3" /> {s.venue ?? "No venue"} · <Clock className="h-3 w-3" /> {fmt(s.starts_at)}</p>
                </div>
                <Badge tone={s.status === "scheduled" ? "violet" : "emerald"} className="capitalize">{s.status}</Badge>
              </div>
              <button onClick={() => setRosterSession(s)} className="mt-3 w-full text-left">
                <div className="flex items-center justify-between text-xs text-faint"><span className="group-hover:text-ink">{s.seated}/{s.capacity} seated</span><span>{pct}%</span></div>
                <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-white/8"><div className={cn("h-full rounded-full", pct >= 100 ? "bg-gradient-to-r from-amber-400 to-rose-500" : "bg-gradient-to-r from-violet-500 to-sky-400")} style={{ width: `${Math.min(100, pct)}%` }} /></div>
              </button>
              <div className="mt-3 flex flex-wrap items-center gap-1.5">
                <ShieldCheck className="h-3.5 w-3.5 text-faint" />
                {s.invigilators.length === 0 ? <span className="text-xs text-faint">No invigilators assigned</span> : s.invigilators.map((i) => (
                  <Badge key={i.id} tone="neutral">{i.name}{i.role === "chief" && " ★"}</Badge>
                ))}
              </div>
              <div className="mt-4 flex gap-2 border-t border-line pt-3">
                <button onClick={() => setInvigSession(s)} className="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs text-violet-600 dark:text-violet-300 hover:bg-violet-500/10"><UserCog className="h-3.5 w-3.5" /> Invigilators</button>
                <button onClick={() => setRosterSession(s)} className="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs text-sky-600 dark:text-sky-300 hover:bg-sky-500/10"><ListChecks className="h-3.5 w-3.5" /> Roster ({s.seated})</button>
              </div>
            </GlassCard>
          );
        })}
      </div>

      {invigSession && <InvigilatorDialog session={invigSession} onClose={() => setInvigSession(null)} onSaved={() => { setInvigSession(null); onChange(); }} />}
      {rosterSession && <SessionRosterDrawer session={rosterSession} rows={roster.filter((r) => r.session_id === rosterSession.id)} onClose={() => setRosterSession(null)} onManage={() => { setRosterSession(null); setInvigSession(rosterSession); }} />}
    </div>
  );
}

/* ── Invigilator assignment dialog ── */

function InvigilatorDialog({ session, onClose, onSaved }: { session: ExamSession; onClose: () => void; onSaved: () => void }) {
  const [staff, setStaff] = useState<StaffUser[]>(demoUsers as StaffUser[]);
  const [picked, setPicked] = useState<Record<string, "chief" | "assistant">>(
    Object.fromEntries(session.invigilators.map((i) => [i.id, i.role === "chief" ? "chief" : "assistant"])),
  );
  const [q, setQ] = useState("");
  const [err, setErr] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => { listUsers().then((u) => { if (u.length) setStaff(u); }).catch(() => {}); }, []);

  const filtered = staff.filter((s) => `${s.full_name} ${s.email}`.toLowerCase().includes(q.toLowerCase()));

  function toggle(id: string) {
    setPicked((p) => {
      const n = { ...p };
      if (id in n) delete n[id]; else n[id] = Object.keys(n).length === 0 ? "chief" : "assistant";
      return n;
    });
  }

  async function save() {
    setErr(null); setBusy(true);
    try {
      await assignSessionInvigilators(session.id, Object.entries(picked).map(([user_id, role]) => ({ user_id, role })));
      onSaved();
    } catch (e) { setErr(e instanceof ApiError ? e.message : "Assigning needs a live session — sign in."); }
    finally { setBusy(false); }
  }

  return (
    <div className="fixed inset-0 z-50 grid place-items-center p-4">
      <div className="absolute inset-0 bg-black/50 backdrop-blur-sm" onClick={onClose} />
      <motion.div initial={{ opacity: 0, y: 16, scale: 0.98 }} animate={{ opacity: 1, y: 0, scale: 1 }} className="glass ring-gradient relative z-10 flex max-h-[80vh] w-full max-w-md flex-col rounded-2xl p-6">
        <div className="flex items-center justify-between">
          <div>
            <h2 className="text-lg font-semibold tracking-tight">Assign invigilators</h2>
            <p className="text-xs text-faint">{session.name ?? "Session"} · {session.venue ?? "No venue"}</p>
          </div>
          <button onClick={onClose} className="grid h-8 w-8 place-items-center rounded-lg text-faint hover:text-ink hover:bg-white/5"><X className="h-4 w-4" /></button>
        </div>

        <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search staff…" className="mt-4 h-10 w-full rounded-lg border border-line bg-black/[0.02] dark:bg-white/[0.03] px-3 text-sm outline-none focus:border-violet-400/50" />

        <div className="mt-3 flex-1 space-y-1.5 overflow-y-auto">
          {filtered.map((u) => {
            const role = picked[u.id];
            const on = role !== undefined;
            return (
              <div key={u.id} className={cn("flex items-center gap-3 rounded-lg border px-3 py-2 transition", on ? "border-violet-400/40 bg-violet-500/5" : "border-line")}>
                <button onClick={() => toggle(u.id)} className={cn("grid h-5 w-5 place-items-center rounded border", on ? "border-violet-400 bg-violet-500 text-white" : "border-line")}>{on && <Check className="h-3.5 w-3.5" />}</button>
                <div className="min-w-0 flex-1"><div className="truncate text-sm">{u.full_name}</div><div className="truncate text-xs text-faint">{u.email}</div></div>
                {on && (
                  <button onClick={() => setPicked((p) => ({ ...p, [u.id]: role === "chief" ? "assistant" : "chief" }))} className={cn("rounded-full px-2 py-0.5 text-[11px] font-medium", role === "chief" ? "bg-amber-500/15 text-amber-600 dark:text-amber-300" : "glass text-muted")}>
                    {role === "chief" ? "★ Chief" : "Assistant"}
                  </button>
                )}
              </div>
            );
          })}
          {filtered.length === 0 && <p className="py-6 text-center text-xs text-faint">No staff match.</p>}
        </div>

        {err && <p className="mt-2 text-xs text-amber-600 dark:text-amber-300">{err}</p>}
        <div className="mt-4 flex items-center justify-between border-t border-line pt-4">
          <span className="text-xs text-faint">{Object.keys(picked).length} assigned</span>
          <div className="flex gap-2"><Button variant="glass" onClick={onClose}>Cancel</Button><Button onClick={save} disabled={busy}>{busy ? "Saving…" : "Save"}</Button></div>
        </div>
      </motion.div>
    </div>
  );
}

/* ── Per-session candidate roster drawer ── */

function SessionRosterDrawer({ session, rows, onClose, onManage }: { session: ExamSession; rows: RosterRow[]; onClose: () => void; onManage: () => void }) {
  const released = rows.filter((r) => r.status === "released").length;
  return (
    <div className="fixed inset-0 z-50 flex justify-end">
      <div className="absolute inset-0 bg-black/50 backdrop-blur-sm" onClick={onClose} />
      <motion.aside initial={{ x: 40, opacity: 0 }} animate={{ x: 0, opacity: 1 }} transition={{ duration: 0.25, ease: [0.22, 1, 0.36, 1] }} className="glass relative z-10 flex h-full w-full max-w-md flex-col overflow-y-auto border-l border-line p-6">
        <div className="flex items-start justify-between">
          <div>
            <h2 className="text-xl font-semibold tracking-tight">{session.name ?? "Session"}</h2>
            <p className="mt-1 flex items-center gap-1.5 text-xs text-faint"><MapPin className="h-3 w-3" /> {session.venue ?? "No venue"} · <Clock className="h-3 w-3" /> {fmt(session.starts_at)}</p>
          </div>
          <button onClick={onClose} className="grid h-8 w-8 place-items-center rounded-lg text-faint hover:text-ink hover:bg-white/5"><X className="h-4 w-4" /></button>
        </div>

        <div className="mt-4 grid grid-cols-3 gap-2">
          <div className="glass rounded-xl p-3 text-center"><div className="text-2xl font-semibold tabular-nums">{rows.length}</div><div className="text-[10px] uppercase tracking-wide text-faint">Seated</div></div>
          <div className="glass rounded-xl p-3 text-center"><div className="text-2xl font-semibold tabular-nums">{session.capacity}</div><div className="text-[10px] uppercase tracking-wide text-faint">Capacity</div></div>
          <div className="glass rounded-xl p-3 text-center"><div className="text-2xl font-semibold tabular-nums text-emerald-600 dark:text-emerald-300">{released}</div><div className="text-[10px] uppercase tracking-wide text-faint">Released</div></div>
        </div>

        <div className="mt-4 flex flex-wrap items-center gap-1.5 text-xs">
          <ShieldCheck className="h-3.5 w-3.5 text-faint" />
          {session.invigilators.length === 0 ? <span className="text-faint">No invigilators</span> : session.invigilators.map((i) => <Badge key={i.id} tone="neutral">{i.name}{i.role === "chief" && " ★"}</Badge>)}
          <button onClick={onManage} className="ml-auto inline-flex items-center gap-1 text-violet-600 dark:text-violet-300 hover:underline"><UserCog className="h-3.5 w-3.5" /> Manage</button>
        </div>

        <div className="mt-4 flex-1">
          <p className="mb-2 text-xs font-medium uppercase tracking-wide text-faint">Candidates</p>
          {rows.length === 0 ? <p className="text-xs text-faint">No candidates in this session yet.</p> : (
            <div className="space-y-1">
              {rows.map((r) => (
                <div key={r.id} className="flex items-center gap-3 rounded-lg border border-line px-3 py-2">
                  <span className="w-7 text-center text-xs tabular-nums text-faint">{r.seat_no ?? "—"}</span>
                  <div className="min-w-0 flex-1"><div className="truncate text-sm">{r.candidate}</div><div className="truncate text-xs text-faint">{r.email}</div></div>
                  <Badge tone={r.status === "released" ? "emerald" : "sky"} className="capitalize">{r.status}</Badge>
                </div>
              ))}
            </div>
          )}
        </div>
      </motion.aside>
    </div>
  );
}

function NewSessionForm({ assessmentId, venues, onDone }: { assessmentId: string; venues: Venue[]; onDone: () => void }) {
  const [venueId, setVenueId] = useState(venues[0]?.id ?? "");
  const [starts, setStarts] = useState("");
  const [duration, setDuration] = useState(60);
  const [err, setErr] = useState<string | null>(null);
  async function save() {
    setErr(null);
    try {
      await createSession(assessmentId, { venue_id: venueId || undefined, starts_at: new Date(starts).toISOString(), duration_minutes: duration });
      onDone();
    } catch (e) { setErr(e instanceof ApiError ? e.message : "Saving needs a live session — sign in."); }
  }
  return (
    <GlassCard className="p-5">
      <div className="grid gap-3 sm:grid-cols-4">
        <select value={venueId} onChange={(e) => setVenueId(e.target.value)} className="h-10 rounded-lg border border-line glass px-3 text-sm outline-none focus:border-violet-400/50">
          {venues.map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}
        </select>
        <input type="datetime-local" value={starts} onChange={(e) => setStarts(e.target.value)} className="h-10 rounded-lg border border-line bg-black/[0.02] dark:bg-white/[0.03] px-3 text-sm outline-none focus:border-violet-400/50 sm:col-span-2" />
        <input type="number" min={1} value={duration} onChange={(e) => setDuration(Math.max(1, +e.target.value))} className="h-10 rounded-lg border border-line bg-black/[0.02] dark:bg-white/[0.03] px-3 text-sm outline-none focus:border-violet-400/50" />
      </div>
      {err && <p className="mt-2 text-xs text-amber-600 dark:text-amber-300">{err}</p>}
      <div className="mt-3 flex justify-end"><Button onClick={save} disabled={!starts}>Create session</Button></div>
    </GlassCard>
  );
}

/* ── Roster ── */

function RosterTab({ rows }: { rows: RosterRow[] }) {
  return (
    <GlassCard glow className="overflow-hidden">
      <div className="hidden grid-cols-12 gap-4 border-b border-line px-6 py-3 text-xs uppercase tracking-wide text-faint sm:grid">
        <div className="col-span-4">Candidate</div><div className="col-span-4">Session</div><div className="col-span-1">Seat</div><div className="col-span-1">Src</div><div className="col-span-2">Status</div>
      </div>
      {rows.length === 0 ? <p className="px-6 py-8 text-center text-sm text-faint">No candidates scheduled yet.</p> : (
        <div className="divide-y divide-[var(--line)]">
          {rows.map((r) => (
            <div key={r.id} className="grid grid-cols-2 items-center gap-4 px-6 py-3 text-sm sm:grid-cols-12">
              <div className="col-span-4"><div className="font-medium">{r.candidate}</div><div className="text-xs text-faint">{r.email}</div></div>
              <div className="col-span-4 text-muted">{r.session ?? "—"}</div>
              <div className="col-span-1 tabular-nums text-faint">{r.seat_no ?? "—"}</div>
              <div className="col-span-1"><Badge tone={r.source === "auto" ? "violet" : "neutral"}>{r.source}</Badge></div>
              <div className="col-span-2"><Badge tone={r.status === "released" ? "emerald" : "sky"} className="capitalize">{r.status}</Badge></div>
            </div>
          ))}
        </div>
      )}
    </GlassCard>
  );
}

/* ── Venues ── */

function VenuesTab({ venues, onChange }: { venues: Venue[]; onChange: () => void }) {
  const [name, setName] = useState("");
  const [capacity, setCapacity] = useState(100);
  const [location, setLocation] = useState("");
  const [err, setErr] = useState<string | null>(null);
  async function save() {
    setErr(null);
    try { await createVenue({ name, capacity, location: location || undefined }); setName(""); setLocation(""); onChange(); }
    catch (e) { setErr(e instanceof ApiError ? e.message : "Saving needs a live venue — sign in."); }
  }
  return (
    <div className="grid gap-6 lg:grid-cols-3">
      <GlassCard className="p-5 lg:col-span-1">
        <h3 className="text-sm font-semibold">Add venue</h3>
        <div className="mt-3 space-y-2">
          <input value={name} onChange={(e) => setName(e.target.value)} placeholder="Name (e.g. Main Hall A)" className="h-10 w-full rounded-lg border border-line bg-black/[0.02] dark:bg-white/[0.03] px-3 text-sm outline-none focus:border-violet-400/50" />
          <input value={location} onChange={(e) => setLocation(e.target.value)} placeholder="Location (optional)" className="h-10 w-full rounded-lg border border-line bg-black/[0.02] dark:bg-white/[0.03] px-3 text-sm outline-none focus:border-violet-400/50" />
          <div className="flex items-center gap-2"><label className="text-xs text-faint">Capacity</label><input type="number" min={1} value={capacity} onChange={(e) => setCapacity(Math.max(1, +e.target.value))} className="h-10 w-28 rounded-lg border border-line bg-black/[0.02] dark:bg-white/[0.03] px-3 text-sm outline-none focus:border-violet-400/50" /></div>
        </div>
        {err && <p className="mt-2 text-xs text-amber-600 dark:text-amber-300">{err}</p>}
        <Button onClick={save} disabled={!name} className="mt-3 w-full"><Plus className="h-4 w-4" /> Add venue</Button>
      </GlassCard>
      <div className="grid gap-3 sm:grid-cols-2 lg:col-span-2">
        {venues.map((v) => (
          <GlassCard key={v.id} className="flex items-center justify-between p-4">
            <div><div className="flex items-center gap-2 font-medium"><Building2 className="h-4 w-4 text-violet-500" /> {v.name}</div><div className="mt-0.5 text-xs text-faint">{v.location ?? "—"}{v.code ? ` · ${v.code}` : ""}</div></div>
            <Badge tone="violet">{v.capacity} seats</Badge>
          </GlassCard>
        ))}
      </div>
    </div>
  );
}

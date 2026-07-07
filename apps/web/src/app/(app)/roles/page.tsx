import { ShieldCheck, Crown, ClipboardList, PenLine, GraduationCap, Check, Minus } from "lucide-react";
import { GlassCard } from "@/components/ui/GlassCard";
import { Badge } from "@/components/ui/Badge";

type Tone = "violet" | "emerald" | "sky" | "amber" | "neutral";

// Static class strings — Tailwind can't see runtime‑built class names.
const CHIP: Record<Tone, string> = {
  violet: "text-violet-700 dark:text-violet-200",
  emerald: "text-emerald-700 dark:text-emerald-200",
  sky: "text-sky-700 dark:text-sky-200",
  amber: "text-amber-700 dark:text-amber-200",
  neutral: "text-muted",
};

const CATEGORIES: {
  name: string; icon: React.ElementType; tone: Tone; blurb: string;
  roles: { name: string; scope: string; blurb: string; caps: string[] }[];
}[] = [
  {
    name: "Administrators", icon: Crown, tone: "violet",
    blurb: "Full control. Super Admin is cross‑tenant and bypasses every check; Institution Admin owns one tenant.",
    roles: [
      { name: "Platform Super Admin", scope: "Cross‑tenant", blurb: "Runs the platform. Bypasses all permission checks via Gate::before.", caps: ["Everything"] },
      { name: "Institution Admin", scope: "One institution", blurb: "Holds every permission within their institution.", caps: ["Everything (tenant)", "Manage users & roles"] },
    ],
  },
  {
    name: "Exam operations", icon: ClipboardList, tone: "emerald",
    blurb: "The staff who run examinations day to day.",
    roles: [
      { name: "Exam Officer", scope: "Institution / org‑node", blurb: "Builds & publishes assessments, schedules candidates, monitors live exams, reconciles grading, reviews proctoring, issues certificates, reads analytics.", caps: ["Author & publish", "Schedule & release", "Monitor & add‑time", "Reconcile grading", "Proctoring review", "Analytics", "Certificates"] },
      { name: "Proctor / Invigilator", scope: "Institution / org‑node", blurb: "Watches sessions, raises/ingests flags, reads their session rosters. Final clear/uphold is an Officer power.", caps: ["Proctoring monitor", "Raise flags", "Read rosters"] },
      { name: "Grader", scope: "Institution / org‑node", blurb: "Marks open‑ended responses in the double‑marking queue.", caps: ["Mark responses", "Read grading queue"] },
    ],
  },
  {
    name: "Question authoring", icon: PenLine, tone: "sky",
    blurb: "The author → review → moderate → approve chain. No question reaches an exam until independently approved.",
    roles: [
      { name: "Question Author", scope: "Institution / org‑node", blurb: "Writes questions, imports banks, drafts new versions.", caps: ["Create & edit items", "Import", "Create & share banks"] },
      { name: "Question Reviewer", scope: "Institution / org‑node", blurb: "Reviews drafts — cannot approve their own authored version.", caps: ["Read items", "Review versions"] },
      { name: "Question Moderator", scope: "Institution / org‑node", blurb: "A second reviewing pass in the workflow.", caps: ["Read items", "Review versions"] },
      { name: "Question Approver", scope: "Institution / org‑node", blurb: "Gives the final approval that makes an item assemble‑able.", caps: ["Read items", "Approve versions"] },
    ],
  },
  {
    name: "Candidates", icon: GraduationCap, tone: "amber",
    blurb: "Everyone who sits exams. A user is a candidate iff they hold the Student role; everyone else is staff.",
    roles: [
      { name: "Student", scope: "Self only", blurb: "Sits/resumes exams and reads their own score, schedule and notifications.", caps: ["Sit / resume exam", "Read own score", "Read own schedule"] },
    ],
  },
];

// Capability matrix — value per role column.
const ROLES = ["Officer", "Proctor", "Grader", "Author", "Rev/Mod", "Approver", "Student"] as const;
type Cell = true | false | string;
const MATRIX: { cap: string; cells: Record<(typeof ROLES)[number], Cell> }[] = [
  { cap: "Author / edit questions", cells: { Officer: "create", Proctor: false, Grader: false, Author: true, "Rev/Mod": false, Approver: false, Student: false } },
  { cap: "Review / approve questions", cells: { Officer: false, Proctor: false, Grader: false, Author: false, "Rev/Mod": "review", Approver: "approve", Student: false } },
  { cap: "Import questions", cells: { Officer: true, Proctor: false, Grader: false, Author: true, "Rev/Mod": false, Approver: false, Student: false } },
  { cap: "Create / share banks", cells: { Officer: true, Proctor: false, Grader: false, Author: true, "Rev/Mod": false, Approver: false, Student: false } },
  { cap: "Build / publish assessments", cells: { Officer: true, Proctor: false, Grader: false, Author: false, "Rev/Mod": false, Approver: false, Student: false } },
  { cap: "Blueprints & scoring rules", cells: { Officer: true, Proctor: false, Grader: false, Author: false, "Rev/Mod": false, Approver: false, Student: false } },
  { cap: "Schedule (venues / map)", cells: { Officer: true, Proctor: "read", Grader: false, Author: false, "Rev/Mod": false, Approver: false, Student: "read" } },
  { cap: "Assign invigilators", cells: { Officer: true, Proctor: false, Grader: false, Author: false, "Rev/Mod": false, Approver: false, Student: false } },
  { cap: "Monitor live / add‑time", cells: { Officer: true, Proctor: false, Grader: false, Author: false, "Rev/Mod": false, Approver: false, Student: false } },
  { cap: "Sit / resume an exam", cells: { Officer: false, Proctor: false, Grader: false, Author: false, "Rev/Mod": false, Approver: false, Student: true } },
  { cap: "Mark open‑ended responses", cells: { Officer: false, Proctor: false, Grader: true, Author: false, "Rev/Mod": false, Approver: false, Student: false } },
  { cap: "Reconcile double marks", cells: { Officer: true, Proctor: false, Grader: false, Author: false, "Rev/Mod": false, Approver: false, Student: false } },
  { cap: "Proctoring — monitor / flag", cells: { Officer: true, Proctor: true, Grader: false, Author: false, "Rev/Mod": false, Approver: false, Student: false } },
  { cap: "Proctoring — clear / uphold", cells: { Officer: true, Proctor: false, Grader: false, Author: false, "Rev/Mod": false, Approver: false, Student: false } },
  { cap: "Analytics (read / compute)", cells: { Officer: true, Proctor: false, Grader: false, Author: false, "Rev/Mod": false, Approver: false, Student: false } },
  { cap: "Certificates", cells: { Officer: true, Proctor: false, Grader: false, Author: false, "Rev/Mod": false, Approver: false, Student: false } },
  { cap: "Reports & notifications", cells: { Officer: true, Proctor: false, Grader: false, Author: false, "Rev/Mod": false, Approver: false, Student: "own" } },
  { cap: "Read own score", cells: { Officer: true, Proctor: false, Grader: false, Author: false, "Rev/Mod": false, Approver: false, Student: true } },
  { cap: "Manage users & roles", cells: { Officer: false, Proctor: false, Grader: false, Author: false, "Rev/Mod": false, Approver: false, Student: false } },
];

const DEMO = [
  { email: "admin@demo.legion.test", role: "Institution Admin" },
  { email: "officer@demo.legion.test", role: "Exam Officer · default login" },
  { email: "author@demo.legion.test", role: "Question Author" },
  { email: "reviewer@demo.legion.test", role: "Question Reviewer" },
  { email: "moderator@demo.legion.test", role: "Question Moderator" },
  { email: "approver@demo.legion.test", role: "Question Approver" },
  { email: "grader1@ · grader2@demo.legion.test", role: "Grader" },
  { email: "proctor@demo.legion.test", role: "Proctor" },
  { email: "ada@ · bode@ · chidi@ · dela@demo.legion.test", role: "Student" },
];

function CellMark({ v }: { v: Cell }) {
  if (v === true) return <Check className="mx-auto h-4 w-4 text-emerald-500 dark:text-emerald-400" />;
  if (v === false) return <Minus className="mx-auto h-3.5 w-3.5 text-faint/40" />;
  return <span className="text-[11px] text-sky-600 dark:text-sky-300">{v}</span>;
}

export default function RolesPage() {
  return (
    <div className="space-y-8">
      <div>
        <h1 className="flex items-center gap-2.5 text-3xl font-semibold tracking-tight"><ShieldCheck className="h-7 w-7 text-violet-500" /> Roles &amp; Access</h1>
        <p className="mt-1 max-w-3xl text-sm text-muted">
          Every user category, what each role can do, and the gates that guard it. Access = <b>bearer‑token auth</b> →
          <b> tenant isolation</b> → <b>role permissions</b> (institution‑wide or scoped to an org node) → <b>per‑object policies</b>.
          A Platform Super Admin bypasses all checks.
        </p>
      </div>

      {/* Role cards by category */}
      {CATEGORIES.map((cat) => (
        <section key={cat.name} className="space-y-3">
          <div className="flex items-center gap-2">
            <cat.icon className="h-5 w-5 text-violet-500" />
            <h2 className="text-lg font-semibold tracking-tight">{cat.name}</h2>
            <Badge tone={cat.tone}>{cat.roles.length} role{cat.roles.length > 1 ? "s" : ""}</Badge>
          </div>
          <p className="text-sm text-faint">{cat.blurb}</p>
          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            {cat.roles.map((r) => (
              <GlassCard key={r.name} className="flex flex-col p-5">
                <div className="flex items-center justify-between">
                  <h3 className="font-medium">{r.name}</h3>
                  <Badge tone="neutral">{r.scope}</Badge>
                </div>
                <p className="mt-1.5 text-sm text-muted">{r.blurb}</p>
                <div className="mt-3 flex flex-wrap gap-1.5">
                  {r.caps.map((c) => (
                    <span key={c} className={`rounded-full border border-line px-2.5 py-0.5 text-[11px] ${CHIP[cat.tone]}`}>{c}</span>
                  ))}
                </div>
              </GlassCard>
            ))}
          </div>
        </section>
      ))}

      {/* Capability matrix */}
      <section className="space-y-3">
        <h2 className="text-lg font-semibold tracking-tight">Capability matrix</h2>
        <p className="text-sm text-faint">Administrators hold every capability. Verifying a certificate is public (no login).</p>
        <GlassCard glow className="overflow-x-auto">
          <table className="w-full min-w-[720px] text-sm">
            <thead>
              <tr className="border-b border-line text-xs uppercase tracking-wide text-faint">
                <th className="px-5 py-3 text-left font-medium">Capability</th>
                {ROLES.map((r) => <th key={r} className="px-3 py-3 text-center font-medium">{r}</th>)}
              </tr>
            </thead>
            <tbody className="divide-y divide-[var(--line)]">
              {MATRIX.map((row) => (
                <tr key={row.cap} className="transition hover:bg-white/[0.02]">
                  <td className="px-5 py-2.5 text-left text-muted">{row.cap}</td>
                  {ROLES.map((r) => <td key={r} className="px-3 py-2.5 text-center"><CellMark v={row.cells[r]} /></td>)}
                </tr>
              ))}
            </tbody>
          </table>
        </GlassCard>
      </section>

      {/* Scope note */}
      <GlassCard className="p-5">
        <h3 className="text-sm font-semibold">Scoping</h3>
        <p className="mt-1.5 text-sm text-muted">
          Any role can be granted <b>institution‑wide</b> or <b>pinned to an org node</b> (faculty → department → programme),
          in which case the permission only applies within that subtree — e.g. a “Grader for the Faculty of Science”.
          Question‑bank visibility is a separate axis: even with read permission, a user only sees banks they own, that
          cover their org subtree, or that are shared with them (unless they hold <i>manage‑all</i>).
        </p>
      </GlassCard>

      {/* Demo accounts */}
      <section className="space-y-3">
        <h2 className="text-lg font-semibold tracking-tight">Demo accounts</h2>
        <p className="text-sm text-faint">Seeded by <code className="rounded bg-white/5 px-1.5 py-0.5 text-xs">php artisan migrate:fresh --seed</code>. Password for all: <code className="rounded bg-white/5 px-1.5 py-0.5 text-xs">password</code>.</p>
        <GlassCard className="divide-y divide-[var(--line)] overflow-hidden">
          {DEMO.map((d) => (
            <div key={d.email} className="flex flex-wrap items-center justify-between gap-2 px-5 py-3">
              <code className="text-sm">{d.email}</code>
              <Badge tone="violet">{d.role}</Badge>
            </div>
          ))}
        </GlassCard>
      </section>
    </div>
  );
}

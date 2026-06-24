import Link from "next/link";
import {
  ShieldCheck, BrainCircuit, Library, BarChart3, Award, Eye, ArrowRight, Lock,
} from "lucide-react";
import { Logo } from "@/components/brand/Logo";
import { GlassCard } from "@/components/ui/GlassCard";
import { ButtonLink } from "@/components/ui/Button";
import { ThemeToggle } from "@/components/ui/ThemeToggle";

const features = [
  { icon: Lock, title: "Split-key answer vault", body: "Questions and answer keys never co-exist. A full database dump never reveals which option is correct." },
  { icon: Eye, title: "Explainable AI proctoring", body: "Tiered signals → a calibrated risk score with a reviewable timeline. It routes to humans, never auto-decides." },
  { icon: Library, title: "Blueprint paper assembly", body: "Balanced exams drawn automatically from the bank by difficulty, topic, and Bloom level." },
  { icon: BrainCircuit, title: "Just-in-time scoring", body: "Answers scored against the vault at submit, with negative, partial, and weighted marking." },
  { icon: BarChart3, title: "Psychometrics", body: "Facility, discrimination, KR-20, Cronbach α, SEM — feeding measured difficulty back into the bank." },
  { icon: Award, title: "Verifiable certificates", body: "Tamper-evident, publicly verifiable credentials with an optional ledger anchor." },
];

export default function LandingPage() {
  return (
    <div className="mx-auto max-w-7xl px-5 sm:px-8">
      <header className="flex items-center justify-between py-6">
        <Logo />
        <nav className="flex items-center gap-2">
          <ButtonLink href="/verify" variant="ghost" size="sm">Verify certificate</ButtonLink>
          <ButtonLink href="/login" variant="glass" size="sm">Sign in</ButtonLink>
          <ThemeToggle />
        </nav>
      </header>

      <section className="relative py-20 text-center sm:py-28">
        <div className="mx-auto mb-6 inline-flex items-center gap-2 rounded-full glass px-4 py-1.5 text-xs text-muted animate-float-in">
          <ShieldCheck className="h-3.5 w-3.5 text-emerald-400" />
          Built for 100 → 1,000,000 concurrent candidates
        </div>
        <h1 className="mx-auto max-w-4xl text-5xl font-semibold leading-[1.05] tracking-tight sm:text-7xl animate-float-in">
          The assessment
          <br />
          <span className="gradient-text">operating system.</span>
        </h1>
        <p className="mx-auto mt-6 max-w-2xl text-lg text-muted animate-float-in">
          Author, deliver, proctor, score, analyze, and certify high-stakes examinations —
          securely, at national scale, from one platform.
        </p>
        <div className="mt-9 flex flex-wrap items-center justify-center gap-3 animate-float-in">
          <ButtonLink href="/dashboard" size="lg">
            Enter the console <ArrowRight className="h-4 w-4" />
          </ButtonLink>
          <ButtonLink href="/verify" variant="glass" size="lg">
            Verify a certificate
          </ButtonLink>
        </div>
      </section>

      <section className="grid gap-4 pb-24 sm:grid-cols-2 lg:grid-cols-3">
        {features.map(({ icon: Icon, title, body }) => (
          <GlassCard key={title} hover className="p-6">
            <div className="grid h-11 w-11 place-items-center rounded-xl bg-gradient-to-br from-violet-500/30 to-transparent text-violet-700 dark:text-violet-200">
              <Icon className="h-5 w-5" strokeWidth={1.8} />
            </div>
            <h3 className="mt-4 text-lg font-semibold tracking-tight">{title}</h3>
            <p className="mt-2 text-sm leading-relaxed text-muted">{body}</p>
          </GlassCard>
        ))}
      </section>

      <footer className="flex flex-col items-center gap-2 border-t border-line py-10 text-center text-sm text-faint">
        <Logo showText={false} />
        <p>Legion CBT — Assessment Operating System</p>
        <Link href="/login" className="text-violet-300 hover:underline">Sign in to the console</Link>
      </footer>
    </div>
  );
}

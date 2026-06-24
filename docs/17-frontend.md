# 17 — Frontend (Next.js)

A premium, dark, glassmorphic web client in [`apps/web/`](../apps/web/) — Next.js 16 +
React 19 + Tailwind v4 + Motion. It pairs with the Laravel API: login and certificate
verification hit real endpoints; the console screens use a demo data layer shaped like the
API responses so the UI is beautiful and explorable without a running backend.

> Note on Stitch: the request was to design via the Google **Stitch** MCP. That server was
> added to Claude Code config but is **not reachable from the build session** that produced
> this, so the UI was hand-built to a premium spec instead. In a session where the Stitch
> MCP is live it can be driven directly; the design system here is a clean target to match.

## Run it

```bash
cd apps/web
npm install          # already installed during scaffold
npm run dev          # http://localhost:3000
# the API (for login + verify):  (repo root) php artisan serve   # http://localhost:8000
```

`NEXT_PUBLIC_API_URL` (in `apps/web/.env.local`) points the client at the Laravel API.

## Design system

- **Light + dark, with a toggle.** Defaults to **light** (institutional/accessible);
  the toggle (in the top bar and every public header) flips a `.dark` class on `<html>`,
  persisted to `localStorage`, applied pre-paint by a tiny inline script so there's no
  flash. Semantic tokens (`--bg`, `--ink`, `--muted`, `--line`, glass background, aurora
  opacity, gradient stops) live in `:root` (light) and are overridden under `.dark`;
  Tailwind color tokens reference them, so `text-ink` / `text-muted` / `border-line` are
  theme-aware automatically, with `dark:` variants for accent text contrast.
- **Aesthetic:** a fixed **aurora** gradient + film grain backdrop (subtle in light, vivid
  in dark); **glassmorphic** surfaces (`backdrop-blur`, hairline borders); an aurora
  violet→indigo→cyan accent used for gradient text, glossy buttons, and glow.
- **Tokens & primitives** live in `src/app/globals.css` (`.glass`, `.gradient-text`,
  `.btn-aurora`, `.ring-gradient`, aurora/grain) and `src/components/ui/*`
  (`GlassCard`, `Button`/`ButtonLink`, `Badge`, `StatCard`).
- **Motion** via `motion/react` — entrance fades, question transitions, animated submit.
- **Type:** Geist (sans + mono), tight tracking on headings.

## Screens

| Route | Auth | What it is |
|-------|------|-----------|
| `/` | public | Landing — hero, feature grid, CTAs |
| `/login` | public | Glossy split-panel sign-in — **wired to `POST /api/auth/login`** (handles the MFA-required response) |
| `/verify` | public | Certificate verification portal — **wired to `GET /api/certification/verify/{token}`**, supports `?token=` deep links, shows authentic/tampered/revoked + the snapshot |
| `/dashboard` | console | Stat cards, gradient score-distribution chart, proctoring-review panel, activity feed |
| `/assessments` | console | Assessment table with status/reliability, links into the runtime |
| `/exam/[id]` | candidate | Full-screen **exam runtime**: server-authoritative countdown, lockdown/proctoring banner, glossy option selection, question palette with answered/flagged state, animated submit |
| `/banks` | console | **Question Banks** — container cards with visibility badges + owner + share counts; create banks (owner org node + visibility) and a **Share dialog** (assign users / staff groups with read or edit, create groups inline) wired to the bank-sharing API |
| `/bank` | console | Questions list (items, type, difficulty, workflow state) → New item |
| `/bank/new` | console | **Item Composer** — compose form (type, stem, options, mark-correct, Bloom) with a **live candidate preview**, plus an **Import** tab (Legion/Aiken/GIFT, validate → created/duplicate/error summary). Wired to `POST /question-bank/items` and `/items/import` |
| `/assessments/new` | console | **Assessment Builder wizard** — Details → Blueprint (total + difficulty distribution sliders with a live composition bar) → Review, then **Create & publish** runs the real chain: scoring rule → blueprint → assessment → section → assemble → publish |
| `/proctoring`, `/analytics`, `/certificates` | console | On-brand stubs listing the live API endpoints that power them |

The console screens share an app shell (`src/components/shell/*`): a sidebar with active-state
nav and a sticky top bar with search + auth/demo indicator.

## Status & next steps

- `npm run build` passes (all 12 routes compile, types check); routes serve 200 with content.
- **Wired to the API:** login, certificate verification. **Demo-data:** dashboard,
  assessments, exam content. The `src/lib/api.ts` client (bearer-token auth, typed helpers)
  is the seam to replace demo data with live calls screen by screen.
- Next: live data for dashboard/assessments, the authoring & grading UIs, the live
  invigilator grid (WebSockets), and a route guard once every console screen is API-backed.

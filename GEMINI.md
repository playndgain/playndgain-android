# Project Rules & Guidelines: Network Anonymity

## Strict Rule: Network Anonymity Across User & Publisher Surfaces

Upstream demand networks (e.g. AdscendMedia, Adswedmedia, AyetStudios, HangMyAds, Monlix, MyChips, Notik, Offery, OGAds, Skylup, Torox, etc.) are internal platform infrastructure and **must NEVER be exposed to end users or publishers**.

### 1. Offerwall (End-User Facing)
- **Zero Network Exposure**: Offer cards, modal sheets, filter pills, URLs, iframe titles, and metadata must never display the name, slug, logo, or direct identifiers of an ad network.
- **Unified Branding**: All inventory must appear seamlessly under the platform/publisher's brand.
- **Multi-network Embedding**: If third-party offerwall frames are displayed, they must be abstracted (e.g., labeled generically as "Partner Wall" / "Extra Offers" or themed without raw network naming).

### 2. Publisher Panel & Publisher APIs
- **No Direct Dealing**: A publisher must never be able to identify which ad network sourced an offer or conversion. Exposing network identities enables publishers to bypass the platform.
- **Offer Catalogue**: The `/dashboard/offers` view, blacklist selectors, exports, and statistics must only present platform-level offer details and publisher-calculated payout amounts (never network names or raw upstream payout rates).
- **Postbacks & Logs**: Outgoing webhooks and publisher-facing conversion logs must never include upstream network identifiers.

### 3. Public Landing Page & Documentation
- **Generic References Only**: Public marketing copy and developer documentation should refer to "demand partners", "verified networks", or "global demand" in aggregate. Do not list proprietary network partner brand names or marks on public marketing strips unless explicitly requested.

### 4. Admin Exception
- Internal admin surfaces (`/admin`), backend network sync jobs (`app/Networks/*`), console commands, and admin-only audit logs are the **only** places where network names and configurations are managed.

---

# Design Rules for AI Agents: No AI Slop

These rules bind every AI agent (Claude, Codex, Gemini, Cursor, Antigravity, any other) that creates or changes UI in this project. They sit on top of `design.md` (the full "Ink & Amber" system). `design.md` says what the brand is; this section says how an agent must behave so it does not drift into the generic look that AI-generated interfaces converge on.

## 0. Before you touch any UI

1. Read `design.md` end to end. Then read the token file for the surface you are on:
   - Marketing site (landing, docs, legal, contact): `resources/css/site-tokens.css`
   - Filament panels (admin, publisher): `resources/css/filament/theme.css`
   - Offerwall: `resources/css/wall.css` and `resources/css/wall-ui.css`
2. Open two or three existing components on the same surface and match them. The codebase is the reference, not your training-set memory of "a modern dashboard".
3. If a request would need a colour, face, radius, shadow, or motion value that does not exist as a token, stop and ask. Do not invent one.
4. Never copy `design.md`, `theme.css`, or tokens from the Advault reference repo. That is a different brand.

## 1. The brand in one line

One face (**Sora**, weight carries hierarchy, no italic), two grounds (**ink** and **paper**), one accent (**amber** as fill, glow, and two-stop gradient), big rounded shapes (32 / 20 / 14 / pill), and money in **gain** green or **loss** rose only. Everything else is a violation until proven otherwise.

## 2. Banned: the AI-slop tells

If your output contains any of these, it is wrong. Fix it before showing it.

**Colour**
- Purple, violet, indigo, or blue-to-purple gradients as an accent. The only purple in the system is `night-600`, and it lives inside the hero and closing-card gradients only. Never on a button, badge, link, icon, or chart.
- Tailwind default palette names (`gray-500`, `slate-800`, `indigo-600`, `emerald-400`, `zinc-*`). The site build disables them on purpose. Panels and wall must use the named tokens too.
- Pure black `#000` or pure white text on ink. Ink is navy-tinted; text is the fog ramp.
- Rainbow status colours. Status uses three tones only: `success` green for settled or live, `warning` amber for waiting, `danger` rose for refused, plus the neutral ramps. Money keeps `gain` and `loss`. No blue, purple, or teal states.
- "Glassmorphism": frosted `backdrop-blur` cards with semi-transparent white borders floating over a blurred gradient.
- Neon glows in any hue but amber. Coloured drop shadows under cards.
- Randomly generated colour palettes and "aesthetic" colour names that are not in the token files.

**Type**
- Inter, Roboto, Poppins, Montserrat, Space Grotesk, Plus Jakarta, DM Sans, or any Google font loaded ad hoc. Sora only, self-hosted. The mono stack is the system one and has no font file.
- Serif display type. It was removed on purpose.
- Gradient text (`bg-clip-text text-transparent`).
- Italics for emphasis. Emphasis is weight and colour.
- Uppercase tracking on anything larger than an eyebrow or label (12px).
- Headlines that are three words in a row with the middle one highlighted in the accent colour.

**Layout and shape**
- The default "hero, three feature cards with icons, testimonials, pricing, FAQ, CTA" scaffold. The landing has its own section order (see `design.md` §2). Add to it only when asked.
- Icon-in-a-coloured-circle above every card title.
- Rounded corners outside the scale: 32 for big containers, 20 for cards, 14 for chips, inputs and frames, pill for buttons and badges. `rounded-md`, `rounded-lg`, `rounded-xl` with Tailwind's defaults are wrong sizes.
- Square corners anywhere on the marketing site.
- Cards inside cards inside cards. One level of container on paper, one bordered card on ink.
- Bento grids for their own sake.
- A stock admin shell (top bar, breadcrumb strip, avatar top-right). The panels have no top bar; the sidebar sits on the ink ground and the page is a raised 28px sheet.
- Fake browser chrome with three coloured dots unless it is the existing `.browser` frame with a real capture inside.

**Motion**
- Scroll-jacking, parallax, count-up numbers, typewriter headlines, orbiting particles, animated gradient backgrounds, cursor followers.
- Any animation library (Framer, GSAP, AOS, Lottie). `site.js` toggles classes and the stylesheet moves things.
- Durations that are not a token. `--motion-fast` 150, `--motion-base` 260, `--motion-enter` 640 (site), `--motion-exit` 200, `--motion-stagger` 70. Panel values live in `theme.css`.
- Bouncy, elastic, or `ease-in-out` curves on entrances. Arrivals use `--ease-out`.
- Anything that moves without a `prefers-reduced-motion` code path that stops it entirely.

**Imagery**
- Generated or stock photography, 3D blobs, abstract "AI" swirls, isometric illustrations, floating device mockups drawn in CSS with fake content.
- HTML mocks of the wall or the panels. Captures are real screenshots made by `scripts/capture-landing.mjs`.
- Emoji as icons in product UI.
- Network partner logos or names on user or publisher surfaces (see the anonymity rules above).

**Copy**
- "Unlock", "Elevate", "Seamless", "Supercharge", "Effortless", "Next-gen", "Revolutionize", "Empower", "Cutting-edge", "Robust", "Leverage", "Delve", "Game-changing", "Harness the power of".
- Invented figures, clients, countries, partners, testimonials, or results. A tile whose number cannot be defended states the capability instead.
- Promises of NET-30 or fixed payout days. Payouts are monthly, clearing on the 1st.
- Sentences with em-dashes, and "It's not X, it's Y" constructions.
- Placeholder text left in (`Lorem ipsum`, `Your headline here`, `John Doe`, `Acme Inc`).
- Landing copy anywhere other than `App\Support\LandingContent`.

## 3. Required: what a correct change looks like

- Every colour, radius, shadow and duration is a token name. `grep` your diff for raw hex, raw `px` radii and raw `ms` values before you finish. The only acceptable raw values are ones already present in the same file for the same purpose.
- Contrast is verified against the pairs listed in `design.md` §8, not assumed. New text-on-ground pairs need a stated ratio.
- One focus ring: amber, 2px, 3px offset, on every interactive element, on every surface.
- Money keeps its colours everywhere a figure is money. Credited is gain, reversed is loss, and neither is used decoratively. There is no gold success toast and no rose form error.
- The page renders completely with JavaScript disabled.
- Lighthouse accessibility stays at 100 on the marketing site.
- Panels: work on Filament's own CSS variables inside `theme.css`. Do not add a second theme file, a Tailwind config override, or inline styles.
- Wall: the six `--wall-*` custom properties are the only inputs a publisher controls. Every other colour is mixed from them. Do not add a seventh.
- After any wall UI change, recapture the landing screenshots with `scripts/capture-landing.mjs`.
- After `npm run build`, run `php artisan responsecache:clear` or you will be testing the old page.

## 4. Process rule: inspect, plan, get approval, then edit

Design work is never a single-shot generation. The order is:

1. Inspect the surface and its tokens (section 0).
2. Write a short plan: which files, which tokens, which existing component it matches, and what you are explicitly not adding.
3. Wait for approval on that plan.
4. Implement. Run the checks in section 3.
5. Show the diff and a screenshot, and name any place you had to choose something the tokens did not cover.

If you are asked to "make it look modern", "make it pop", "add some polish", or "make it beautiful", that is a request to apply this system more carefully, not a licence to add gradients, glows, and animations. Ask which element is not working and fix that one thing.

## 5. Self-check before you hand over

Answer each honestly. Any "yes" means go back.

- Would this screen look at home in a generic SaaS template gallery?
- Is there a purple or blue gradient anywhere?
- Did I load a font, library, or icon set that was not already in the project?
- Did I use a Tailwind default colour, radius, or shadow?
- Did I write a number, a client, a country, or a testimonial that is not backed by data in the app?
- Does anything move on scroll?
- Did I draw a mock of the product instead of using a real capture?
- Would the owner have to ask "why is this different from the rest of the site"?

# JewelFlows landing redesign — local review candidate

> Historical cloud handoff. See [the takeover handoff](jewelflows-takeover-local-review-2026-10-06.md) for current local evidence and the pre-staging checkpoint. Cloud SHAs and NOT RUN statements below describe the source packet; do not replay its patch on the integrated candidate.

User scope: modern, bold, responsive public homepage. Promote Retail and Dhiran only. Existing users must find login immediately; new users must reach registration without searching the page.

## Continuity

- Isolated cloud branch: `design/landing-retail-dhiran`, based on `da095defed5b29b88986dcb931f2dea7c430d1ee` (UI continuation).
- This repository was reconstructed from a source export. Its commits are NOT the laptop's true Git ancestry. Use the separate patch; do not merge this reconstructed history.
- Original laptop UI worktree: `/home/himanshu/Desktop/jewelflow-worktrees/ui-navigation-batch1`, exported branch `ui/navigation-batch-3`, original HEAD `1d01ba440dd43ba9df15d888b2fdf34a23b00049`.
- Original landing template SHA256: `fad3871f152353ac1a7fe78777dfde80ee700021fbb6436b74c817c29c16e227`.
- Earlier navigation work is preserved. The landing patch is separate and does not contain those changes. No laptop, server, database, authentication handler, route, subscription gate or deployment was changed.

## Design and content

- Gold, ink and warm neutral palette, bold typography, a dedicated green Dhiran section.
- Sticky header with always-visible Log in and Register controls. Each opens a native product chooser; Retail and Dhiran have separate account links. Direct Retail registration also appears in the hero and Retail section.
- Separate Retail and Dhiran content, with no Manufacturer offer, fabricated reviews, user counts, pricing or free-trial promises.
- Hero uses a compact illustrative retail workspace. All figures there are explicitly sample data, not customer data or a screenshot of the actual product.
- User follow-up: retain visible device mockups without making them dominate. Added a dedicated “At the counter. On the move.” section between Retail and Dhiran. It reuses existing `laptopview-960.webp` and `phoneview-480.webp` assets in balanced cards, with lazy loading, explicit dimensions and contained images. Side by side on desktop; stacked on phones. Existing artwork is an illustration, not a current exact screenshot; the laptop lettering is distorted in the original asset. The page labels this honestly. The phone hardware artwork does not establish an iOS release; product copy says Android.
- Layout, accessible focus, skip link, reduced motion, native FAQs, 44px account controls and no-JavaScript registration access included.
- System fonts, existing favicon and existing WebP device assets; no new dependency, image service or external font request. The two WebP files total about 151 KiB and were read from the public site, not modified. They already exist in the full laptop repository but were omitted from the source-only export. Inline CSS is confined to this standalone template, matching the prior landing architecture. No shared app styles changed.

## Entry route handling

Retail uses existing named `home`, `login` and `register` routes. Root authenticated redirects remain unchanged in routes/web.php.

Dhiran registration reuses `Realm::dhiranRegisterUrl(request())`, preserving the existing explicit override/disabled/staging behavior. Login is derived only when this helper returns the standard `/register` path. Nonstandard campaign URLs do not invent a sibling login. If the helper returns null, Dhiran account links are omitted and the section offers an email enquiry using configured support email. The normal production configuration exposes both products in both account menus. Confirm effective deployment configuration in a real Laravel render before release.

## Measured checks

`tests/js/landing.browser.cjs`: 17 checks passed, zero page errors.

- Thirteen widths: 320, 360, 390, 430, 568, 680, 768, 820, 900, 1024, 1280, 1440, 1920px. No page overflow; both product menus stay within the viewport; account controls are at least 44px tall and remain at the top after scrolling.
- At every width both device images load, have visible dimensions of at least 100px in each direction and fit their illustration areas. Device-section screenshots hide fixed header/skip-link overlays only during that component capture; full-page screenshots retain the actual page.
- Keyboard Retail login, Retail registration click, Dhiran link destinations, mutual menu closure, Escape with focus return and outside-click dismissal.
- Anchor clears the sticky header; native FAQ opens; no Manufacturer promotion.
- Disabled-Dhiran fixture contains no production Dhiran account URL.
- Registration chooser works without JavaScript.
- Desktop and phone full-page screenshots inspected. JS syntax and diff whitespace checks pass.

This browser check uses the ACTUAL template HTML/CSS/JS after narrowly substituting Blade URLs/config. It does not run PHP. Auth destinations are synthetic route targets, not real sign-ins or registrations. Dhiran links are inspected without remote navigation. Existing `DhiranCrossPromoUrlTest` was read but not executed. Device images are embedded in the standalone preview; application markup references existing assets. In an exported source tree, set `JF_LANDING_ASSETS` to their folder; in the full repository the browser check reads public/images.

NOT RUN: PHP/Blade compilation, live effective URL configuration, real auth transitions, physical devices, Safari/Firefox or staging/production. The live homepage could not be retrieved through web search; the supplied source export was the implementation baseline.

## Delivery / later application

The packet contains `landing-only.patch`, this README, the Blade source, browser check, desktop/mobile screenshots and a standalone `landing-preview.html`. Open the HTML directly to review the design; it uses example figures, production entry links and the repository's default support email. It performs no registration itself.

Before applying later, inspect the actual laptop HEAD, branch and status, and verify the original template checksum above. Preserve all pending UI work. Create a separate local landing branch, run `git apply --check` on this packet's patch, and apply only if clean. Do not reset/clean/stash another agent's changes. Record actual local SHAs after verification.

Before staging, compile Blade and run the existing Dhiran URL/realm front-door tests with the unchanged TestDatabaseGuard and disposable local database. Check the homepage's actual Retail/Dhiran destinations under the target configuration. This is a design review candidate, not deployment approval.

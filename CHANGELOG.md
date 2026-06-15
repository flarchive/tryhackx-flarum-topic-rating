# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.4.11] - 2026-06-14

> Adds an opt-out for live polling and makes the aggregate-recalculation raw SQL
> table-prefix safe. No new migrations, no breaking changes, and no change to the
> shared discussion-list layout module — independent of `flarum-thumb-sliders`.

### Added
- **"Live rating updates" toggle** (`tryhackx-topic-rating.realtime_enabled`,
  default **on**). When off, no background polling runs at all — neither the
  discussion-page poll nor the ratings-modal poll — so a forum that doesn't want
  near-real-time rating updates can drop that load entirely. Ratings still load, and
  still update on the user's own rate/unrate and on page reload. (Requested by a
  user.) The poll-interval setting keeps applying while live updates are on.

### Changed
- **`RatingRecalculator` is now table-prefix safe.** Its atomic aggregate `UPDATE`
  uses raw correlated subqueries; the query builder prefixes the outer
  `table('discussions')` but not the raw subquery table names, so they now prepend
  the connection's table prefix (`getTablePrefix()`). No effect on the default
  (empty-prefix) install; fixes the recompute on installs configured with a table
  prefix. (The reviewer's suggested `getTable()` does *not* include the prefix, so
  it wouldn't have fixed this — `getTablePrefix()` is required.)

### Docs
- README: documented the new live-updates toggle and added a scaling tip — raise
  the poll interval to 30–60 s, or turn live updates off, on forums with many
  concurrent discussion-page viewers.

## [2.4.10] - 2026-06-14

> Final low-severity audit polish on top of 2.4.9. One new admin setting (no
> migration), no breaking changes, and no change to the shared discussion-list
> layout module — independent of `flarum-thumb-sliders`.

### Added
- **Configurable discussion-page poll interval.** A new admin setting *Live update
  interval (seconds)* (`tryhackx-topic-rating.poll_interval`, default 8, clamped
  5–300) controls how often an open discussion page polls for new ratings, so large
  forums can dial back per-visitor background load. `RatingPolling` reads it live —
  with the existing ±20% jitter, exponential back-off and hidden-tab pause still on
  top; the ratings-modal poll keeps its own faster cadence.

### Changed
- **Rating-aggregate recalculation extracted to a `RatingRecalculator` service.**
  The atomic `UPDATE … (SELECT …)` that recomputes `rating_count` /
  `rating_average` / `last_rated_at` moved out of the `Rating` AbstractModel into a
  dedicated service (container-resolved, injected into the write controllers),
  keeping the model free of raw SQL. Behaviour is identical — same single
  race-safe statement, same correlated subqueries, same connection. The raw
  aggregate UPDATE has no Eloquent equivalent, so it's now an explicit, documented,
  scoped exception rather than a model helper.
- **`DeleteRatingController` checks discussion visibility before the rating
  lookup**, matching `CreateRatingController`'s ordering — a restricted or missing
  discussion now returns 404 consistently before any rating row is touched. No
  security or behaviour change (the `user_id` constraint already scoped the lookup
  to the actor's own row).

## [2.4.9] - 2026-06-13

> Small polish follow-up to 2.4.8 (the last low-severity audit items). No new
> migrations, no breaking changes, and no change to the shared discussion-list
> layout module — independent of `flarum-thumb-sliders`.

### Performance
- **The ratings-modal poll now jitters and backs off too.** 2.4.8 gave the
  discussion-page poll (`RatingPolling`) ±20% jitter + exponential back-off but left
  the ratings-modal poll on a fixed 5s `setInterval`. The modal poll now uses the
  same self-scheduling timeout (±20% jitter, back-off to ~40s on consecutive
  errors), so many clients opening the modal on a trending discussion no longer fire
  in lockstep. Base cadence (5s) and the hidden-tab pause are unchanged.

### Robustness
- **A failed post-rating refresh no longer leaves an unhandled rejection or a stuck
  widget.** `StarRating.refreshDiscussion()` — the follow-up `GET` after a successful
  rate/unrate — had no `.catch()`; on failure the promise rejected unhandled and the
  widget didn't re-render. It now catches and re-renders, and the (jittered)
  background poll reconciles the average shortly after — the rating write itself
  already succeeded, so nothing is lost.

### Changed
- `RatingPolicy` now uses constructor property promotion for its injected
  `SettingsRepositoryInterface` / `ExtensionManager`, matching the rest of the
  codebase. Purely cosmetic — no behaviour change.

### Notes
- Two audit items were deliberately left as-is: **`RatingPolicy::rate()`'s length /
  nesting** is not refactored — it's the core authorization method and extracting a
  helper purely for line count risks a security regression for no functional gain;
  and **`flarum-tsconfig`** is not added — it's dev/IDE-only (the build works without
  it) and none of the sibling TryHackX extensions ship it, so adding it only here
  would break suite consistency.

## [2.4.8] - 2026-06-13

> Scale-hardening pass for large (millions-of-discussions) forums. **Two new
> migrations** — additive indexes, run `php flarum migrate`. No breaking changes
> and no change to the shared discussion-list layout module — independent of
> `flarum-thumb-sliders`.

### Performance
- **Discussion-page poll now jitters and backs off.** `RatingPolling` moved from a
  fixed `setInterval` to a self-scheduling timeout: each delay carries ±20% jitter
  so many concurrent readers don't poll in lockstep (avoids a synchronized
  "thundering herd"), and consecutive failures back off exponentially (up to ~64s),
  recovering to the base rate on success. The 8s base cadence and the hidden-tab
  pause are unchanged.
- **The viewer's rating preload is now bounded.** `DiscussionRatingFields` loaded
  every rating the viewer had ever cast on each discussion-list render; it now caps
  the bulk load at the 5000 most-recent ratings and resolves anything beyond that
  with a single cached per-discussion lookup. Normal users (far below the cap) are
  unaffected — still one indexed query — while a pathological voter with tens of
  thousands of ratings can no longer hydrate an unbounded result set per request.
  (Flarum 2.x exposes no list of the page's models inside a field getter — search
  results are null there — so a page-scoped `whereIn` isn't available; the cap is
  the bound that fits the resource layer.)
- **Indexed the rating sort/filter columns on `discussions`** (two migrations:
  `last_rated_at`, then `rating_average` + `rating_count`). The bundled
  `flarum-homepage-blocks` discussion-list sorts/filter read these columns directly:
  "rated in period" (`WHERE last_rated_at >= ?`), "Recently rated"
  (`ORDER BY last_rated_at`), "Avg rating" (`ORDER BY rating_average`) and "Number
  of ratings" (`ORDER BY rating_count`). On a forum with millions of rows those
  predicates/orders would otherwise scan or filesort the whole table. This extension
  owns the columns, so the indexes live here. Additive/online — but on a very large
  `discussions` table the build can take a while; plan the migration accordingly.
  (The opt-in, non-default "Steam DB" sort orders by a computed confidence
  expression that no plain column index can serve; its output was verified correct,
  and materialising it into an indexed column is deliberately deferred as a separate,
  higher-risk change.)

### Notes
- The rating aggregate columns deliberately **stay on the `discussions` table** (an
  audit suggested a companion table). The bundled homepage-blocks rating filter and
  Steam-rating sort read `last_rated_at` / `rating_average` / `rating_count`
  directly in `WHERE` / `ORDER BY` on the discussion query — a companion table would
  force a join on that hot path. The scale fix is the indexes above, not a side table.
- Audit items left unchanged: the silent `.catch` blocks in `StarRating` /
  `RatingsModal` / `ResetRatingsModal` are **not** silent — those requests pass no
  custom `errorHandler`, so Flarum's default handler already shows an error alert;
  adding `app.alerts.show()` would double it. And `Rating::recalculate()` keeps the
  base query-builder update (not `Discussion::where()->update()`, which would
  auto-touch `discussions.updated_at` on every rating).

## [2.4.7] - 2026-06-13

> Hardening + cleanup from a third audit pass. No new migrations, no breaking
> changes, and no change to the shared discussion-list layout module — independent
> of `flarum-thumb-sliders`, no coordinated update needed.

### Changed
- **`Rating::recalculate()` no longer interpolates the discussion id into raw
  SQL.** The three aggregate subqueries now correlate against the row being
  updated (`discussion_ratings.discussion_id = discussions.id`) instead of
  embedding `$id` via string interpolation; the id is bound once in the `WHERE`
  clause and no raw value substitution remains. Still a single atomic
  `UPDATE … (SELECT …)`, so the race-safety is unchanged. (The `$id` was already
  int-cast and guarded — this removes the interpolation pattern entirely so a
  future edit can't reintroduce a risk.) Verified the rewrite recomputes the
  correct count/average on MySQL.

### Robustness
- **Background rating polls now surface genuine server errors.** Both the
  discussion-page poll (`RatingPolling`) and the ratings-modal poll previously
  discarded every failure (`errorHandler: () => {}`); they now log unexpected
  `5xx` responses to the console for diagnosability, while still never alerting
  the user (an alert every poll interval would be noise) and still ignoring the
  `401/403/404` that are expected in a polling context (session expiry, deleted
  discussion). `RatingPolling.poll()` also gained the `.catch()` it was missing,
  so a failed poll no longer leaves an unhandled promise rejection.

### Notes
- The recurring high-severity claim that **the moderator toggle/reset endpoints
  404** (because they read the id from query params, not route attributes) is
  **still a false positive** — re-verified live this round: `POST
  /api/discussions/9/toggle-rating` returns `200`. Flarum's `RouteHandlerFactory`
  merges route parameters into the query params, so `getQueryParams()['id']` is
  correct. The suggested "fix" `$request->getAttribute('id')` would actually
  **break** the endpoints (Flarum stores path params under the `routeParameters`
  attribute, not as individual request attributes), so it was deliberately not
  applied.
- The claim that the facade comment in `Rating::recalculate()` is wrong (that
  Flarum 2.x registers Laravel facades) remains **incorrect** — `DB::table()`
  throws *"A facade root has not been set"* in this build. The unbounded
  `actorRatingsFor()` preload is unchanged for the reasons in the 2.4.5 / 2.4.6
  notes (single indexed query; no per-page bound exposed by the field-serialiser
  context).

## [2.4.6] - 2026-06-12

> Robustness fix from a follow-up audit pass. No new migrations, no breaking
> changes, **no frontend/asset changes** (PHP only — `js/dist` untouched), and no
> change to the shared discussion-list layout module — independent of
> `flarum-thumb-sliders`, no coordinated update needed.

### Fixed
- **`RatingPolicy::loadDiscussionTags()` no longer swallows genuine database
  errors.** Its defensive `catch (\Throwable)` (a safety net for the magic `tags`
  relation) also masked real failures — a lost DB connection or a missing column
  after a botched migration was silently turned into "no tags", quietly denying
  rating on tagged discussions instead of surfacing a diagnosable error. Genuine
  database errors (`\PDOException`, which `QueryException` extends) are now
  re-thrown; only non-database problems still degrade gracefully to an empty tag
  set, so a tag hiccup never 500s the whole discussion list. No behaviour change
  in normal operation.

### Notes
- Several audit findings were investigated and **deliberately not changed**:
  - *"Moderator toggle/reset endpoints are broken (read the id from the query
    string, not route attributes → 404)."* **Not a bug** — Flarum's
    `RouteHandlerFactory` merges route parameters into the query params before the
    controller runs (`withQueryParams(array_merge(...))`), so
    `getQueryParams()['id']` is correct. Verified in core and with a live
    `POST /api/discussions/{id}/toggle-rating` returning `200`.
  - *"`Rating::recalculate()`'s comment about facades is wrong — Flarum 2.x
    registers Laravel facades."* **The comment is correct** — `DB::table()` throws
    *"A facade root has not been set"* in this build (verified); facades are not
    registered. The intentional, guarded `$id` int-cast interpolation stays (the
    correlated-subquery `UPDATE` can't bind in the SET clause; no injection risk).
  - The unbounded `actorRatingsFor()` preload (single indexed query — see 2.4.5
    Notes), the legacy global `discussion.rate.bypass` permission (never exposed in
    the permission grid; the historical migrations are kept intentionally), and the
    byte-identical shared layout module (deliberate, runtime drift-guarded) are
    unchanged.

## [2.4.5] - 2026-06-12

> Performance, i18n and internal-cleanup pass from a follow-up audit. No new
> migrations, no breaking changes, and **no change to the shared discussion-list
> layout module** (`LAYOUT_VERSION` stays 5) — this release is independent of
> `flarum-thumb-sliders` and needs no coordinated update.

### Performance
- **Live polling now pauses while the browser tab is hidden.** Both the
  discussion-page poll (`RatingPolling`, every 8 s) and the ratings-modal poll
  (every 5 s) skip their request when `document.hidden` is true and resume on the
  next tick once the tab is visible again. Readers routinely leave discussion
  tabs open in the background; previously every one of them kept hitting
  `GET /discussion-ratings/poll` on a fixed interval — a large amount of server
  load on a busy forum for data nobody is looking at. The interval keeps ticking
  (a cheap no-op while hidden), so no listener lifecycle is added and active tabs
  behave exactly as before.

### Changed
- **Backend rating-validation messages are now translatable.** The two
  `ValidationException` strings in `CreateRatingController` ("rating must be
  between 1 and 10", "rating is disabled for this discussion") were hardcoded in
  English; they now come from new `tryhackx-topic-rating.api.*` locale keys
  (EN + PL) resolved through an injected `TranslatorInterface`. These messages
  are normally unreachable through the UI (the frontend guards the range and the
  disabled state), so this is a consistency fix for the defensive 422 path.
- **`Rating::recalculate()` uses the discussion's own DB connection** instead of
  instantiating a throwaway `Rating` model just to obtain one
  (`(new self())->getConnection()` → `$discussion->getConnection()`). Same
  default connection, same race-safe atomic `UPDATE … (SELECT …)` — the aggregate
  recalculation is unchanged; this only drops the needless instance.

### Notes
- Two audit suggestions were **intentionally not applied**: moving the four
  denormalised rating columns off the `discussions` table, and bounding the
  per-request `userRating` preload. The columns follow Flarum core's own
  denormalisation pattern and are read on the hot discussion list, where a side
  table would force a join; the preload is already a single indexed query (the
  N+1 was removed in 2.4.0), with no clean per-page bound exposed by the
  field-serialiser context.

## [2.4.3] - 2026-06-08

> Accuracy, i18n and robustness fixes from a follow-up audit pass. No new
> migrations. The shared discussion-list layout module was hardened (clobbered-global
> guard + version-drift warning) and stays byte-identical with `flarum-thumb-sliders`,
> which ships the same change in its **2.1.1** release (coordinated). The shared
> avatar section is unchanged.

### Fixed
- **Per-tag permissions help now matches the actual policy.** The `tag_config_help`
  text (admin panel, EN + PL) claimed *"any Disabled tag blocks rating"*, but the
  policy is **most-permissive-wins**: a single `Disabled` tag does not block rating
  when another of the discussion's tags allows it (a topic is non-rateable only when
  *all* of its tags resolve to Disabled; bypass groups can still rate). Reworded to
  match the code and the README's "how the policy decides" section.
- **Polish localisation:** the discussion-page *"Last rated:"* label
  (`forum.last_rated`) was left untranslated as `"Last Rate:"`; it now reads
  *"Ostatnia ocena:"*. The English string was also tidied from *"Last Rate:"* to
  *"Last rated:"*.
- **Polish moderation-controls help** referenced the English button captions
  *"Disable Rating" / "Reset All Ratings"*; it now uses the Polish labels actually
  shown in the UI.

### Changed
- **Concurrent first-time rating writes are race-safe.** `CreateRatingController`
  used a find-then-insert that could hit the unique `(discussion_id, user_id)` index
  and 500 if the same user raced two initial submits; it now catches the
  unique-constraint violation and falls back to an update.
- `rating_disabled` is now cast to `boolean` on the `Discussion` model (alongside the
  existing `last_rated_at` datetime cast) instead of relying on call-site casts.
- **Shared discussion-list layout module hardened** (byte-identical with
  `flarum-thumb-sliders`): the idempotency guard tolerates a clobbered
  `window.tryhackxDLL` global (non-object) and warns in the console on a
  `LAYOUT_VERSION` mismatch between installed copies — catching a partial
  multi-extension upgrade instead of silently using the older layout. Rendered
  layout unchanged (`LAYOUT_VERSION` stays 5).

### Removed
- Dropped two unused locale keys (`forum.controls.reset_confirm`,
  `forum.ratings_modal.rate_label`) in both EN and PL.

### Docs
- README: corrected the "Latest" callout (was stuck on 2.3.0) and the Compatibility
  section (this 2.x line needs Flarum 2.0+ / PHP 8.3+; the 1.8+ support is the
  separate, legacy 1.x branch).

## [2.4.2] - 2026-06-07

> Follow-up hardening from a second audit pass.

### Security
- **Rating write/moderation endpoints now scope to discussions the actor can
  see.** `CreateRatingController`, `DeleteRatingController`, `ToggleRatingController`
  and `ResetRatingsController` resolved the discussion with a bare
  `Discussion::findOrFail()`; they now use `whereVisibleTo($actor)->findOrFail()`,
  matching the poll endpoint. Closes the edge case where a member could rate (or a
  moderator could toggle/reset) a thread they aren't allowed to view, given its id
  and a tag that's rate-enabled but view-restricted. `assertCan` was always the
  primary gate; this is defense-in-depth and consistency.

### Changed
- Dropped a stray `console.error` from the ratings modal's load handler, and
  guarded the admin Support modal's clipboard copy (`navigator.clipboard` absence
  + a `.catch`) so it fails silently instead of throwing.

## [2.4.1] - 2026-06-07

> Hotfix for 2.4.0: the ratings modal crashed on render, plus two scaling indexes.

### Fixed
- **Ratings modal showed an endless spinner** (both logged-in and guest). The
  2.4.0 rewrite called `flarum/common/helpers/avatar`, which isn't present in
  this Flarum 2.x build's runtime registry (`flarum.reg.get` returned `undefined`),
  so `renderRatingItem` threw `TypeError: … is not a function` on every row and
  the list never rendered. Avatars are now built from the `User` model's own
  `avatarUrl()` / `color()` / `displayName()` getters — no dependency on the
  helper — so the modal renders again.

### Performance
- **Indexes on `discussion_ratings`** (migration): `user_id` for the per-request
  "all of this actor's ratings" load behind the `userRating` field, and
  `(discussion_id, updated_at)` for the poll endpoint's "any ratings since X?"
  range scan. Additive — safe on large tables. Matters on big forums where the
  table isn't tiny.

## [2.4.0] - 2026-06-07

> **Security + hardening release.** Closes an unauthenticated ratings-enumeration
> hole, removes an N+1 query on the discussion list, makes rating aggregates
> race-safe, and brings the JS layer in line with Flarum conventions. No new
> migrations.
>
> ⚠️ **Behaviour notes:**
> - `GET /api/discussion-ratings` now **requires a `discussion_id`** and is
>   scoped to discussions the actor can view. Called without one it returns an
>   empty list instead of every rating. The ratings modal always passes the id,
>   so the UI is unaffected; only external callers relying on the unscoped dump
>   are.
> - The admin-only `GET /tryhackx-topic-rating/tag-config` endpoint and the
>   `tryhackxTopicRatingBypassGroups` forum attribute have been **removed** (both
>   were unused by the extension).

### Security
- **Unauthenticated ratings enumeration fixed.** `GET /api/discussion-ratings`
  with no `discussion_id` previously returned the **entire** `discussion_ratings`
  table — every user↔rating↔discussion mapping — to anonymous visitors. The
  listing now requires a positive `discussion_id` and is scoped through
  `whereVisibleTo($actor)`, so ratings on threads the actor can't see no longer
  leak. `GET /discussion-ratings/poll` is visibility-scoped the same way.
- **`bypass_groups` is no longer serialized to the public forum payload.** It
  exposed which groups have privileged rating access to every visitor and was
  never read by the frontend (the backend already resolves `canRate` etc.).

### Changed
- **N+1 removed on the discussion list.** The `userRating` field used to issue
  one query per serialized discussion; the actor's own ratings are now loaded in
  a single query per request and read from a map.
- **Rating aggregates are recalculated atomically.** Count / average /
  last-rated are now written in one `UPDATE … (SELECT …)` statement shared by the
  create, delete and reset controllers, instead of a read-modify-write that two
  concurrent ratings could interleave and leave out of sync.
- **Rating fields extracted to a dedicated, injected class**
  (`Api\DiscussionRatingFields`) — no more `resolve()` inside per-model
  serializer closures, and the single injected policy shares its per-request
  memoization across the whole list.
- **Ratings modal uses `app.store` / `app.request` and real `Rating` models**
  plus the core `avatar()` helper, instead of raw `fetch()` with hand-built
  JSON:API parsing and manually-assembled plain objects.
- **The discussion-page poll pauses while the ratings modal is open** (the modal
  runs its own faster poll), removing the duplicate request in that window.
- `DiscussionPage.onremove` now chains through `extend()` instead of a manual
  prototype monkey-patch that could go stale if another extension reassigned it.
- The admin "Reset extension settings" cancel-button styling now `extend()`s the
  modal directly instead of a session-long, document-wide `MutationObserver`.

### Removed
- **`GET /tryhackx-topic-rating/tag-config`** admin endpoint
  (`GetTagConfigController`) — dead code; the admin reads these settings directly.
- **`tryhackxTopicRatingBypassGroups`** forum-serialized attribute (see Security).

### Fixed
- `pollForNewRatings()` no longer leaks an unhandled promise rejection on every
  failed poll tick (it now goes through `app.request` with an error handler).

### Other
- `composer.json`: PHP constraint tightened `^8.2` → `^8.3` to match Flarum 2.x's
  minimum (and a stray tab in the authors block fixed).
- The `Rating` JS model now declares its `discussion` relationship, matching the
  API resource.

## [2.3.0] - 2026-06-04

> Finer **per-tag control** and a few visibility options on top of 2.2.0: manage
> discussions tagged with **only a secondary tag** (or with **no tags**), hide
> empty widgets from non-voters, hide "frozen" ratings on topics that became
> non-rateable, and choose whether the rating moderation items follow the
> widget's visibility in the ⋮ menu. No new migrations.
>
> ⚠️ **Behaviour note:** discussions tagged with **only a secondary tag (no
> primary)** now default to **not** rateable (previously they were always
> rateable). Re-enable specific ones under *Secondary tags used on their own*.

### Added
- **"Secondary tags used on their own (no primary tag)"** — a new list in the
  per-tag admin (below the primary tree) that controls discussions tagged with
  **only a secondary tag and no primary**. Each standalone secondary tag is
  *Enabled* / *Disabled*; **default is Disabled** (opt-in), with collapse +
  *Enable all* / *Disable all*. A secondary-only discussion is rateable if at
  least one of its secondary tags is enabled here. Stored as bare keys in the
  existing `tag_config`.
- **"Allow rating on discussions with no tags"** (`untagged_enabled`, default
  **on**). When off, tagless discussions show no rating widget and can't be
  rated (`ratingDisplayMode` returns `hidden` for them).
- **"Hide empty stars from users who can't rate"** (`hide_empty_for_nonvoters`,
  default **off**). On the discussion list, topics with **no ratings yet** don't
  render the widget for viewers who can't rate (guests, restricted groups).
  Already-rated topics still show. Serialized as
  `tryhackxTopicRatingHideEmptyForNonVoters`.
- **"Hide ratings on rating-disabled topics"** (`hide_disabled_ratings`, default
  **on**). When a topic can no longer be rated because **all of its tags are
  disabled** (e.g. its tag was switched to a disabled one *after* it was rated),
  the widget — including any existing ratings — is hidden for viewers who can't
  rate, instead of showing them read-only. Prevents "rate it, then lock in the
  score" abuse. Viewers who *can* rate (e.g. bypass groups) still see it. Wires
  up the previously-dormant `isRatingDisabled()` check.
- **"Always show rating controls in the discussion menu"**
  (`show_moderation_controls`, default **off**). By default *Disable Rating* and
  *Reset All Ratings* appear in the discussion's ⋮ dropdown **only when the
  rating is shown** on that topic (hidden when its tags hide the rating —
  managing it would do nothing; the per-topic moderator toggle is exempt so it
  can still be re-enabled). Turn this on to always show them. Still gated by the
  moderation permissions. Serialized as
  `tryhackxTopicRatingShowModerationControls`.

### Changed
- The **"Secondary tags used on their own"** admin list renders as a vertical
  stack (matching the primary tree) and is **collapsed by default**.

### Fixed
- **Discussions tagged with only a secondary tag (no primary) were always
  rateable**, ignoring the per-tag settings — because the policy defaulted an
  unconfigured standalone secondary to *enabled* and the admin had no way to
  disable it. Such discussions now default to **Disabled** and are controlled by
  the new "Secondary tags used on their own" list. (Discovered via a per-tag
  report where only one primary tag was enabled yet announcement-style,
  secondary-only topics still showed ratings.)

## [2.2.0] - 2026-06-03

> Big feature release: pick **how the rating looks on the discussion list**
> (six per-device display styles), make the compact single star **clickable**
> with an optional **rate-from-the-modal** control, and manage the author
> **avatar** from a new **shared** Desktop/Mobile section that stays in sync
> with `flarum-thumb-sliders`. Plus a batch of ratings-modal fixes. No breaking
> changes, and **no new migrations in this extension** — the shared avatar
> keys default safely on their own.

### Added
- **Per-device discussion-list rating styles.** New settings *Display style —
  desktop* (`list_style_desktop`) and *Display style — mobile*
  (`list_style_mobile`), each one of six styles:
  - *Full stars — in place* (the original meta column, above views / replies —
    **default, unchanged**);
  - *One star + score* (e.g. `★4.6`) *— in place*;
  - *One star (empty / half / full by score) + score — in place*;
  - *Full stars — after the title*;
  - *One star + score — after the title*;
  - *One star (empty / half / full) + score — after the title*.
  Graded-star buckets use the 0–10 scale: empty `0–3.33`, half `3.33–6.67`,
  full `>6.67` (10 = 5.0). Compact and after-title styles are **display-only**.
  Serialized as `tryhackxTopicRatingListStyleDesktop` / `…Mobile`.
- **Clickable single star + in-modal rating** (cascading admin toggles, shown
  only when a single-star style is selected on either device):
  - *Make the single star clickable* (`single_clickable`, default **on**) —
    the whole star + number opens the ratings modal (even after the title,
    where the click is captured so it doesn't follow the title link). When
    **off**, the star is display-only and topics with no rating yet show
    nothing at all.
  - *Allow rating inside the modal* (`single_modal_rate`, default **off**) —
    adds an interactive, centred rating control at the top of the ratings
    modal so users can rate (or change their rating) without leaving the list.
  Serialized as `tryhackxTopicRatingSingleClickable` /
  `tryhackxTopicRatingSingleModalRate`.
- **Shared avatar section — Desktop & Mobile** (also present in the
  `flarum-thumb-sliders` admin; same underlying setting). Per device choose
  *Show avatar* / *Replace with thumbnail when the topic has an image* /
  *Always replace with thumbnail* / **Hide avatar**. The new **Hide** mode
  drops the avatar entirely for a lighter list (handy on mobile) and works
  even without thumbnails. Both extensions write the **same** neutral
  `tryhackx-avatars.mode_desktop` / `…_mobile` keys (serialized as
  `tryhackxAvatarModeDesktop` / `…Mobile`), so changing it in one is reflected
  in the other. The "replace" modes self-detect Thumb Sliders — with it absent
  they behave as *Show*.

### Changed
- **Shared discussion-list layout module → `LAYOUT_VERSION = 5`**
  (byte-identical with `flarum-thumb-sliders`). It now resolves the per-device
  avatar mode and the rating placement (meta column vs end of the title), and
  still re-flows on the phone-breakpoint flip.
- **Ratings modal** restyle: the row separator is darkened to `#3c3c41`, and
  the optional in-modal rate control is centred with no label.
- **Admin selects** (rating-style + avatar) now render at their natural width
  instead of stretching edge-to-edge, and clip long labels with an ellipsis
  (`max-width: 100%`) so they never overflow on narrow / mobile widths — the
  full label still shows when the native picker opens.

### Fixed
- **Ratings modal title showed `Ratings ({undefined})`** when the list
  response had no `meta.total`. The count now falls back to the discussion's
  own `ratingCount`, so the heading is always correct — on the list *and* the
  discussion page.
- **Compact single empty star** rendered in a washed-out grey/white; it now
  uses the star colour (yellow) to match the half / full states.

## [2.1.2] - 2026-06-01

> Discussion-list layout polish for the shared restructured layout
> (mirrored with `flarum-thumb-sliders`). Tags get a proper mobile home,
> the desktop meta column stops colliding with the controls dropdown, and
> the layout now re-flows reliably on resize / orientation change. Pure
> frontend/layout — no new settings, no migrations, no API changes, no
> change to the rating policy or permissions.

### Changed
- **Mobile tag placement.** On phones the discussion's tags now render
  inside `.DiscussionListItem-main`, on their own line right below the
  author/info line, wrapping across the full row width (`flex-wrap`)
  instead of stacking one-per-line in the narrow right-hand meta column
  (which made tag-heavy rows very tall). Tablet/desktop is unchanged —
  tags stay in the meta column. The rating stars stay in the meta column
  on every viewport.
- **Mobile tag size pinned** to `font-size: 11px` on
  `.DiscussionListItem-mobileTags` (≈9px labels with `TagLabel`'s own
  `0.85em`), so tags on mobile match or sit just below the desktop tag
  size instead of inheriting the larger body font.
- Mobile `.DiscussionListItem-main` right padding dropped from `4px` to
  `0` — the flex `gap` already separates the main column from the meta
  column, so the extra padding was redundant.
- Shared layout module bumped to `LAYOUT_VERSION = 4` (byte-identical to
  the copy in `flarum-thumb-sliders`).

### Fixed
- **Desktop meta column no longer slides under the controls (⋮) dropdown.**
  On tablet+ the meta column (tags / rating / views / replies) now reserves
  `28px` on its right so it clears the absolutely-positioned controls icon.
  Previously, on rows without a thumbnail or rating, the meta column reached
  far enough right to overlap the ⋮ (most visible on hover/active rows).
- **Tag layout re-flows reliably when the viewport crosses the phone
  breakpoint** (window resize, device rotation). The previous build
  recorded the "last breakpoint" inside `onbeforeupdate`, which never runs
  on mount, so the *first* desktop↔mobile switch after page load was
  silently dropped (tags didn't move until a second toggle). The flag is
  now written in `contentView` (runs on every render incl. mount), and an
  `onbeforeupdate` override forces a one-off rebuild on the render where
  the breakpoint flips — beating core's `SubtreeRetainer`, which otherwise
  pins each row to whichever layout it first rendered with.

## [2.1.1] - 2026-05-31

### Fixed
- README screenshots now load from the local `assets/` folder
  (relative paths) instead of GitHub raw URLs. This means they render
  correctly on `composer require` installs that mirror the package to
  their own infrastructure, and they keep working offline.
- Updated the `Topic_Rating.png` caption to reflect the 2.1 admin
  panel (per-tag permission tree, bypass-group picker, display-mode
  selector).

> No code changes versus 2.1.0 — same migrations, same PHP/JS bundles,
> same API surface. Pure docs/asset reference fix.

## [2.1.0] - 2026-05-31

> Major feature release: **per-tag rating permissions** with a dedicated
> bypass-group picker. Activates automatically when the bundled
> `flarum/tags` extension is enabled, otherwise the extension behaves
> exactly as before (no behaviour change for tag-less installs).

### Added
- **Admin API endpoint** `GET /api/tryhackx-topic-rating/tag-config`
  (admin-only). Returns a structured JSON snapshot of every
  extension setting (parsed, not raw strings) — handy for migration
  scripts, audits, external dashboards.
- **Orphan cleanup** — listener on `Flarum\Tags\Event\Deleting` strips
  `{tagId}` and any compound `*_{tagId}` / `{tagId}_*` entries from
  `tag_config` when a tag is deleted in Tags admin (no more dead IDs
  growing in the JSON).
- **Bypass-group picker quality-of-life** — *Select all* / *Clear*
  toolbar buttons above the pills.
- **Override badge** on each primary-tag card — small `✏ N` chip
  showing how many secondary tags under that primary have explicit
  non-`inherit` state.
- **Per-tag rating permissions** (`tryhackx-topic-rating.tag_config`,
  visible only when `flarum/tags` is enabled). Two-level admin tree:
  - **Primary tags** are the cards in the top grid. Each one picks one of
    three states — *Enabled* (default, anyone with the global Rate
    permission can rate), *Disabled* (nobody can rate, unless they hold
    bypass), *Allow groups* (only the picked groups can rate).
  - **Secondary tags** are listed under each primary card (expand the
    card). Their default state is *Inherit* (follow the parent), and
    they can override their parent with *Enabled / Disabled / Allow
    groups* independently per primary.
  - Toolbar: **Expand all / Collapse all** buttons appear when at least
    one primary card has expandable children.
- **Bypass-group picker** (`tryhackx-topic-rating.bypass_groups`,
  default `["1"]` — Admin only). Group-based pill selector replaces the
  old permission-grid bypass row. Admins remain default-bypass but can
  be removed by deselecting the Admin pill (testing tag rules without
  switching accounts). Empty list = nobody bypasses (only Flarum-core
  admin-of-everything still applies outside this extension's policy).
- **Display mode for restricted users**
  (`tryhackx-topic-rating.display_when_restricted`,
  default `readonly`): pick what users without rating permission see —
  *Read-only stars* (default visual, no interaction), *Hidden* (widget
  removed entirely), or *Message* (short note instead of stars).
- **New `ratingDisplayMode` field** on the Discussion API resource —
  one of `rate | readonly | hidden | message`. Frontend renders accordingly.
- Polish & English locale strings for everything above, including an
  in-UI admin note explaining how Flarum's hard-coded "admin has every
  permission" interacts with the bypass picker.

### Changed
- **Centralised bypass logic** — single public
  `RatingPolicy::actorBypassesGlobal(User)` is the only place that
  parses the bypass-group list. `extend.php` (serializer fields) and
  any downstream code call it instead of re-implementing the JSON
  decode + intersect dance.
- **Per-request memoization in `RatingPolicy`** — `tag_config`,
  `bypass_groups` and the actor → group-ids list are parsed at most
  once per request. On a 20-discussion list page that's a
  measurable drop in `SettingsRepositoryInterface::get` calls.
- **Email-activation gate now applies consistently on both paths**
  (legacy and tag-aware). Pre-2.1, the legacy path
  short-circuited to *allow* when the user already held
  `discussion.rate`, even with an unconfirmed email — that loophole
  is closed. Existing forums where unconfirmed users were rating
  through the loophole should turn *Allow unactivated accounts* on
  if they want to preserve the old effect.
- **Empty-state UX in the tag tree** — distinguishes "no tags at all"
  from "only secondary tags exist; mark at least one as primary".
- **Dead code removed** in `RatingPolicy` (`isDescendantOf()` and
  `resolveTagState()` — leftovers from an earlier parent_id-based
  iteration that was superseded by `resolveDiscussionStates()`).
- **Unused locale keys removed** (`tag_inherits_from`,
  `tag_allow_groups_label`).
- `RatingPolicy::rate()` now resolves a discussion's effective state
  across **all** of its tags (primary × secondary combinations):
  - *Most-permissive wins*: if any tag combination would allow the actor
    to rate, rating is allowed.
  - *Disabled is overridable*: a primary tag set to *Disabled* can still
    permit rating in a secondary that's individually overridden to
    *Enabled* or *Allow groups*.
  - *Bypass short-circuits everything*: any tag for which the actor
    bypasses counts as allowed.
- `canRateRequiresActivation` API field now respects the bypass-group
  picker (no false "activate your account" tooltip for bypass holders).
- `RatingPolicy::isRatingDisabled(Discussion)` is a new public helper
  that returns `true` only when **every** resolved (primary, secondary)
  combination is *Disabled*. Used by `ratingDisplayMode` so the
  hidden/message modes kick in only for "truly closed" discussions.
- Admin tree layout: card grid (`grid-template-columns:
  repeat(auto-fill, minmax(360px, 1fr))`) instead of a single
  vertical list, so the per-tag table uses horizontal space and
  doesn't sprawl downward.

### Fixed
- **Tag relation lookup never returned anything.** The previous
  `method_exists($discussion, 'tags')` guard always returned `false`
  because Flarum/Tags registers `tags` dynamically via `Extend\Model`
  (resolved through `__call`, not as a real method). As a result the
  per-tag policy branch was silently skipped and the legacy "any user
  with `discussion.rate` can rate everywhere" path always won. Replaced
  with a direct relation access wrapped in `try/catch` — falls back to
  an empty collection if Tags isn't installed.
- `canRate` / `ratingDisplayMode` now stay in sync on the discussion
  list after editing tag-config (frontend reads them on each refresh).

### Migrations
- `2026_05_31_000000_add_rating_bypass_permission.php` — historical
  no-op for fresh installs; left in place so existing installs that
  ran it remain consistent.
- `2026_05_31_000001_revoke_default_bypass_permission.php` — clears
  any default `discussion.rate.bypass` grant from `group_permission`
  (the row is no longer used; the bypass group list is now a setting).

### Notes for administrators
- **Test as a non-admin account** to see the real effect of *Disabled*
  / *Allow groups*. Flarum hard-grants admins every permission; the
  bypass-group picker is the only place where you can opt the Admin
  group out of bypass for this extension.
- After upgrading, run `php flarum migrate` and `php flarum cache:clear`.

## [2.0.6] - 2026-05-30

### Added
- Admin setting **Show rating in discussion list**
  (`tryhackx-topic-rating.show_on_list`, default on) — visibility toggle
  for the stars on the homepage list. When off, the rating still appears
  on the discussion page.
- Admin setting **Allow rating from the discussion list**
  (`tryhackx-topic-rating.rate_on_list`, default on) — controls whether
  the list stars are interactive (click + hover preview); when off, the
  list is read-only.
- Polish (`pl.yml`) strings for the two new list-related settings.

### Changed
- The rating in the discussion list is now interactive by default — users
  can rate a topic without opening it.
- The list rating is rendered in the right meta column (sibling of the
  discussion link), so clicking a star no longer navigates to the topic.
- Desktop content padding of the restructured discussion list aligned
  to the same horizontal rhythm as mobile: `12px 6px 12px 6px` (was
  `12px 16px 12px 0`), so the row breathes evenly on both sides.
  Mirror of the same edit in `flarum-thumb-sliders` — both extensions
  ship the identical structural CSS for the shared layout.
- Override of core's tablet+ `.DiscussionListItem` padding from
  `25 / 15` to a symmetric `12 / 12` so the outer row padding matches
  the inner content padding. Mirrored in `flarum-thumb-sliders`.
- Cap the right meta column at `max-width: 18%` on the restructured
  layout, so a discussion with many tags wraps them down inside the
  narrow column instead of pushing the row wider. Mirrored in
  `flarum-thumb-sliders`.

### Fixed
- **Cancel button in core's "Reset extension settings" modal** now
  uses Flarum's standard `Button--inverted` style so it doesn't render
  as a plain borderless button. Implemented with a small
  `MutationObserver` that adds the `Button--inverted` class to the
  Cancel button when the modal appears in the DOM (the modal class
  is lazy-loaded by core and not statically importable, so we can't
  extend its prototype directly). Each TryHackX extension registers
  this independently; repeated `classList.add` of the same class is
  a no-op.
- **Hover preview now actually shows on the discussion list.** Flarum's
  `DiscussionListItem` uses a `SubtreeRetainer` that swallows Mithril
  redraws unless `discussion.freshness` changes — which meant the
  `hoveredValue` state of `StarRating` never reached the DOM and the
  stars stayed at the average. `StarRating` now paints the star classes
  directly on the DOM in its `onmouseenter` / `onmouseleave` handlers
  (the same pattern `flarum-thumb-sliders` uses), so the preview appears
  on both the list and the discussion page.

## [2.0.2] - 2026-04-09

### Changed
- Moved support button to the top of the admin settings page.
- Removed margin-top / padding-top / border-top CSS from the support button section.

## [2.0.1] - Initial tracked release

### Added
- 5-star rating system with half-star precision (0.5 to 5.0).
- Real-time rating average updates via polling.
- Ratings modal showing all individual ratings with user info.
- Moderator controls to toggle ratings on/off per discussion and reset all ratings.
- Permission-based rating, toggling, and resetting.
- Optional setting to allow unactivated users to rate.
- English and Polish translations.

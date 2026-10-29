# SDD ledger — plan: docs/superpowers/plans/2026-10-27-usabilidade-etapa-1.md

Base: 891f482. Branch: codex/usabilidade-etapa-1. Worktree: D:/Projetos/.codex-worktrees/Conselho-usabilidade-etapa1.

User approval: Pode implementar, after proposal to start stage 1 (F01–F11). Stages 2/3 remain outside this implementation.

Ruling: Use independent file ownership and parallel implementation where safe — developer requests proactive parallel work; shared app.js/layout integration remains with coordinator.
Ruling: Create isolated checkout without another approval prompt — user has approved implementation and developer explicitly authorizes routine reversible isolation.
Ruling: Do not create commits while multiple workers run; coordinator reviews and integrates the tested diff locally. No push/deploy/migrations.

## Preflight conflict scan

| Task/pair | Shared interface | Resolution |
|---|---|---|
| 1 | CSS, table markup | No backend or app.js edits; CSS uses --app-header-height. |
| 2 | Plan submit and delivery updates | Atomic backend validation; separate asset integrated by coordinator. |
| 3 | Existing dashboard statuses | Change display classification only. |
| 4 | Fallback autosave | Capture form synchronously; retain collaboration protocol. |
| 5 | Front-controller errors and shared layout | Allowlisted POST routes; no auth/autosave interception; bounded session values. |
| 1 + 4 | --app-header-height | Coordinator owns ResizeObserver; CSS task owns consumer. |
| 2 + 4 | Asset inclusion | Only coordinator edits layout. |
| 2 + 5 | AJAX error shape and pending form | Preserve HTTP failure; structured errors; no competing template edits. |
| 4 + 5 | app.js, layout | Same coordinator executes sequential edits. |
| 1 + 3 | Separate APC templates | admin table versus submissions; no overlap. |

Baseline dependency copy completed. The initial test started prematurely while vendor was incomplete; after the copy finished, the original checkout baseline passed with 213 tests and 1164 assertions.

## Tasks

- Task 1: complete. CSS and APC table labels; three viewport families checked. Independent shared-UI review approved.
- Task 2: complete. Atomic save/finalize, per-student saves and attachment operations, preserved pending fields/files, leave warning and session recovery. Focused 32 tests / 110 assertions passed; meaningful red failures preceded the implementation. Final independent review approved.
- Task 3: complete. Actual dashboard statuses distinguish completion, no class and pending configuration/legacy. Focused 15 tests / 139 assertions passed after three meaningful red failures. Independent states review approved.
- Task 4: complete. Fallback finalization waits for confirmed autosave and captures the form before await. Browser save-failure/retry verified; collaboration protocol unchanged. Header measurement supplies the toolbar offset.
- Task 5: complete. Allowlisted, bounded, one-use form recovery excludes secrets/files and authorization/AJAX errors. Six tests / 34 assertions passed. CPF conflict, schedule review and dependent upload selects verified in the browser. A reviewer-found restoration issue was reproduced, fixed and retested.
- Task 6: complete. Verified in isolated checkout and integrated into the clean original checkout at unchanged HEAD 891f482. Full suite passed in both: 231 tests / 1265 assertions. PHP lint, both JavaScript syntax checks, collaboration:check and diff check passed in the original checkout; hashes of all 23 integrated files matched. Final reviewer found an expired-session CSRF loop; fixed and browser-verified with reauthentication, same-user token renewal and rejection of another user without a POST. No open findings.

## Browser and print evidence

- Browser uses only isolated synthetic SQLite databases, local storage and a fake Secretaria API on ports 8190/8191. No operational database, credentials or external storage were used.
- Twenty HTML entries have regression checks in 1440x900, 390x844 and 844x390: 18 administrative/consultation entries plus login and new plan. No horizontal page overflow or runtime page errors in this matrix. Relevant forms and original error reproductions have additional captures.
- Council pending autosave failure keeps text and permits retry; successful retry saves the latest text and finalizes. APC invalid finalization keeps newly added abilities and fields; valid finalization stores the submitted version. Cancelled confirmation sends no POST. Native form finalization with JavaScript disabled also stores the latest edit.
- Saving one student keeps the other draft; saving a delivery preserves its chosen file; uploading/removing an attachment preserves its pending observation. First delivery exposes attachment upload without a reload. The leave warning fires for pending edits.
- A 419 and a real logout/login in another tab preserve the draft and allow manual retry with the renewed token. A different authenticated user cannot resume the old form. No automatic replay of a failed POST.
- A4 portrait Council and A4 landscape Consolidated PDFs exported with print media and visually inspected. This is browser/PDF evidence, not physical printing or device validation.

## Integration ruling

Use the already authorized local implementation workflow: copy only the reviewed changed/new files into the clean original checkout after checking its HEAD and status, then run its full suite. No commit, remote merge, push or deploy. Keep the isolated worktree for reproducibility. Higher-priority user/developer instructions authorize this reversible local integration without another approval menu.

Integration completed. Durable verification report and screenshots/PDFs: `C:/Users/Cliente/Documents/Codex/Auditorias/Conselho/2026-10-27/implementacao-etapa-1/validacao.md`. Original audit artifacts were preserved.

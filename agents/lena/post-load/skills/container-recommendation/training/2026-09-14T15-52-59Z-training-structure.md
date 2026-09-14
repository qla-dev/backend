# Training: prepare future training structure

- Recorded at: 2026-09-14T15:52:59Z
- Skill: post-load/skills/container-recommendation.md
- Status: structure-only
- Source: current workspace conversation, user request to create timestamped Markdown files for future training
- Supersedes: none

## User instruction

Every new training iteration should create a new timestamped Markdown file inside the skill tree. Main prompt edits remain allowed but should be as small as possible. Prepare the structure for future training.

## Intended behavior

Maintain an append-only history of future training instructions, changes and validation. Keep the main skill path stable. Existing prompt content remains in place; earlier iterations have not been retrospectively recreated. Training history is not loaded as runtime instructions.

## Example

Not applicable: structure-only setup.

## Main prompt changes

None.

## Files changed

- `backend/agents/lena/AGENTS.md`: workflow for future training across Lena skill trees.
- `backend/agents/lena/post-load/skills/container-recommendation/training/README.md`: directory layout and runtime distinction.
- `backend/agents/lena/post-load/skills/container-recommendation/training/TEMPLATE.md`: reusable training record template.
- This timestamped file: initial structure-only record.

## Validation

Inspected LenaSkillCatalog discovery patterns: nested training Markdown and the plural AGENTS.md maintenance file are outside the selectable prompt patterns. No runtime code or main prompt changes. No database operations or live AI calls were needed for this setup.

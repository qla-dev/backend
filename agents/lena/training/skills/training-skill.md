---
name: training-skill
description: Capture a new LenaAI skill the admin wants to teach - when it applies, its steps, rules and examples - as a skill brief Claude or Codex turns into a skill file.
---

# Training skill

Use when the admin wants LenaAI itself to learn something: a new workflow, a rule, domain knowledge, a calculation, or a better way to answer a kind of question.

1. Find the trigger: which LenaAI mode it belongs to (general, free, legal, post-load, storage, tracking, booking, hs, about-load, or shared by every mode) and which kind of user message should activate it.
2. Collect the procedure: the steps LenaAI follows, the questions it asks, any calculation, and the exact shape of the answer.
3. Collect the rules: what it must never do, limits, legal or business constraints, and the sources it relies on.
4. Collect examples: at least one realistic user message with the ideal reply. Attached images serve as examples of documents or screens the skill must understand.
5. Write the Training brief with Type "skill", adding Mode, Trigger, Procedure, Rules, Examples and a proposed file path such as backend/agents/lena/tracking/skills/delay-notice.md.

A developer agent writes the skill later from this conversation, as a Markdown file with name and description front matter and a closing "Name" section in Bosnian, English and German. Never say the skill is already active.

## Name

- bs: Trening vještine
- en: Training skill
- de: Skill trainieren

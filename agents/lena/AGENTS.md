# Lena skill training workflow

For every new user-requested training iteration, create a new Markdown record inside the affected skill's tree. For a skill at `<mode>/skills/<skill>.md`, use `<mode>/skills/<skill>/training/YYYY-MM-DDTHH-mm-ssZ-short-topic.md`. For shared skills use `skills/<skill>/training/`; for an `AGENT.md` use `training/` beside that file. Create missing directories as needed.

- Use the actual current UTC timestamp, with no Windows-invalid colons. Never overwrite an earlier record; add a numeric suffix if timestamps and topics collide.
- Read the main prompt and relevant previous training records before making changes. Copy the container recommendation training template as a starting point for other skill trees.
- Record the source conversation/message references when known, the relevant user instruction, intended behavior, examples, files changed and validation. Include only the source details needed to explain the change; do not copy whole private conversations or documents.
- Keep records as history. New corrections go into a new timestamped file, referencing the superseded record or rule. Do not silently rewrite earlier training history or fabricate historical timestamps.
- Editing the main prompt is allowed when needed to activate the requested behavior. Make the smallest focused change and remove directly conflicting instructions; avoid rewriting unrelated sections or repeating the entire history in the prompt.
- Training records are maintenance documents, not automatically loaded runtime prompts. Do not claim that creating a record alone changes Lena's responses. Record whether the iteration is structure-only, pending, or applied to the runtime prompt, and identify the relevant prompt edits.
- Do not add training records or templates to the selectable skill catalogue or resource registry. Keep the existing main skill path stable unless the user explicitly requests a runtime restructuring.
- Validate according to the actual change. Do not claim live AI behavior was tested when only files or deterministic tests were checked. Follow inherited database safety and localization rules.

For structure-only requests, prepare the directories, template and a timestamped setup record without changing runtime behavior. Existing training may remain in the main prompt; do not reconstruct prior history unless requested.

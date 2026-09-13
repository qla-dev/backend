# Guided voice and text answers

Interpret typed text and speech transcripts using exactly the same conversation context. Input method never changes the intended action or the application's selected reply language.

Apply this skill when the latest message responds to a pending guided question. Resolve natural phrases against that question's available choices, not against an unrelated earlier question. "Da, želim", "Da, hajde", "Da, može", "Hajde da počnemo", "Yes, go ahead", and "Ja, bitte" can confirm the pending action. Punctuation, case, missing diacritics, and Serbian Cyrillic do not change their meaning. A refusal, correction, condition, or unclear answer is not confirmation. Ask a concise clarification when meaning is ambiguous.

Follow the server-resolved guided action when supplied. After a confirmed start-add action, continue the guided load flow and its next incomplete step; do not repeat start confirmation or request all load fields at once. Never claim the load canvas is open or data was saved unless server context confirms it. A yes to document upload is not a yes to creating or publishing a load. Never publish a load on the strength of an unrelated affirmative answer.

## Interpret every answer against the expected format

The executable skip phrases in [guided-voice-skip.json](guided-voice-skip.json) resolve answers such as "odabrati kasnije" against the current pending step, just like its Choose later button. Once skipped, proceed to the next incomplete step; do not acknowledge the skip and then ask the same question again.

Before interpreting an answer, inspect the current guided step supplied by the application: its question, field key, available options and their stored values, whether it accepts one or multiple choices, expected data type, units, format hints, validation constraints, and whether skipping is allowed. Use the actual current step, not a remembered option list or an unrelated earlier question. If required format or option information is missing, ask for clarification rather than inventing it.

Map the meaning of the user's complete answer to the format that this step expects. Do not require an exact button label or exact wording. Apply this to typed text and speech transcripts in every active language. Keep the application's canonical option value separate from the localized wording shown to the user.

- Single choice: match a synonym, paraphrase, or clear description to one of the offered options. If the available transport choices include road transport, "kamionom" can select that option using its supplied value. "The second one" selects the second option only when the displayed order is known and unchanged. Never invent an option or select several values for a single-choice field.
- Multiple choices: return the offered values for all explicitly selected options, excluding negated choices. Interpret "prva i treća, bez druge" only against the known current option order. Respect the step's required representation and selection limits.
- Numbers and units: convert spoken numbers and explicit units to the expected unit. For a weight field in kilograms, "dvije i po tone" means 2500 kg; for a pallet-count field, "dvanaest paleta" means 12. Respect integer requirements and allowed ranges. Ask about ambiguous decimal separators or missing units when they change the result.
- Dimensions: identify the named length, width, and height, convert explicit units, and format them in the required order. Do not invent an omitted dimension or swap dimensions to make the answer fit.
- Dates and times: use the application's current date and timezone for relative expressions such as "sutra". Produce the step's required date/time format. Ask about ambiguous dates, missing years, or timezones when the context does not resolve them.
- Locations: extract the supplied city, address, or country for the requested pickup or delivery field. Preserve the user's spelling and details; do not invent a street, postal code, coordinates, or a country for an ambiguous place name.
- Free text: preserve the cargo description, notes, and other relevant details. Do not turn descriptive answers into DA or NE or force them into an unrelated option.
- Skip or none: use the provided skip/none choice only when the user clearly requests it and the current step allows it. Zero, unknown, not applicable, and skipped are different values.

Check the normalized value against the current step's constraints before accepting it. If there is one clear valid interpretation, use that value through the existing guided action/field protocol. If the answer is invalid, incomplete, or matches multiple options, keep the same step and ask one focused question showing the relevant choices or a valid format example. Do not silently truncate, clamp, round, or substitute a value.

Adapt the user-facing response to the interpretation: briefly confirm the understood value in the selected interface language and proceed to the next incomplete question only after the application accepts it. If the user asks a question instead of answering, answer it and keep the current step pending. If the user corrects an earlier value or supplies several fields at once, use only the supported correction/update path, preserve unrelated data, and never claim unsaved changes were accepted. Never expose internal option identifiers or action markers as explanatory prose.

## Interpret intent as DA or NE

For every pending binary question, interpret the meaning of the entire latest answer in context. Do not require the literal words "da" or "ne", and do not treat the examples or executable patterns as an exhaustive vocabulary. Apply the same interpretation to en, de, bs, hr, and sr, for typed and spoken answers alike.

Internally normalize an unambiguous answer to DA (accepts the specific pending action) or NE (declines that action). Preserve the selected interface language in the user-facing response; do not output DA or NE as a replacement for the next guided question.

- Starting a load: "želim da dodam novi teret", "da želim započeti kreiranje novog tereta", and "hajde da krenemo" mean DA when answering the start question. "Odustajem" and "ne želim dodavati teret" mean NE.
- Uploading a document: "imam tovarni list", "mogu poslati dokument", and "učitat ću ga" mean DA. "Nemam dokument", "bez dokumenta", and "želim ručno unijeti podatke" mean NE and continue with manual entry.
- Continuing a draft: "nastavimo gdje smo stali" and "želim završiti ovaj teret" mean DA. "Ne nastavljaj ovaj nacrt" means NE.
- English: "I would like to proceed" means DA; "I would rather enter it manually" means NE to a document-upload question.
- German: "Ich möchte weitermachen" means DA to continuing; "Ich habe kein Dokument" means NE to having a document to upload.

Resolve negation, corrections, and references to the pending action before deciding. "Nemam dokument, ali želim nastaviti" means NE to upload, not NE to creating the load. A leading "da" never overrides a later refusal. "Da, ali ne sada" declines starting now. "Ne, ipak hajde" can confirm when the correction clearly accepts the pending action. If the answer is conditional, contradictory, unrelated, or does not establish a clear choice, ask one short clarification and leave the action pending. Never force uncertainty into DA or NE.

Map DA or NE only to the matching option offered by the current question: start_add_yes/start_add_no, upload_yes/upload_no, or continue_add_yes/continue_add_no. Never substitute another action or infer permission to publish. For questions requesting a location, quantity, date, or description, preserve the supplied field value instead of reducing it to DA or NE.

The application must resolve and execute the matching action before claiming a state change. If the server has not resolved an action, do not pretend that understanding the intent has opened the canvas. The executable resource provides deterministic routing for supported phrases; prose instructions alone do not execute application transitions.

## Confirming entry into post-load

When the pending question offers `start_add_yes` and `start_add_no`, a full sentence such as “da želim započeti kreiranje novog tereta” confirms entry just as “da želim” does. The same applies to “Da, zelim da kreiram novi teret”, “Yes, I want to create a new load”, and “Ja, ich möchte eine neue Ladung erstellen”. Interpret the whole sentence; a condition such as “ali ne sada” does not authorize starting.

The executable confirmation patterns for this skill live in [guided-voice-confirmations.json](guided-voice-confirmations.json). The server loads this resource before selecting the conversation mode. Patterns match normalized, complete answers, with punctuation and diacritics removed and Serbian Cyrillic transliterated. Action-specific patterns apply only to that pending action; common yes/no patterns apply to the pending start, upload, or continue choice. Maintain the patterns here with the skill rather than adding phrase rules to the PHP router.

On confirmed entry, activate `start_add_yes` and the post-load canvas. If no document or draft data exists, ask whether the user has a document to upload. Otherwise ask the next incomplete questionnaire question. A conversational statement that creation has started is not a replacement for activating the guided flow.

For other guided answers, interpret ordinary speech naturally in the current field: quantities and units, dates, locations, cargo descriptions, and option names. Preserve the user's values. Do not invent missing fields, overwrite unrelated answers, or guess ambiguous numbers. Reuse existing validation and questionnaire order for both typed and spoken input.

The client decides whether a reply is spoken. Only a submitted voice message requests automatic speech for its reply. Typed answers, guided buttons, and uploads do not request automatic speech; explicit Play is a separate replay action. Do not insert speech-control instructions into the answer. Never expose or read internal action markers as prose.

## Name

- en: Guided voice and text answers
- bs: Vođeni glasovni i tekstualni odgovori
- hr: Vođeni glasovni i tekstualni odgovori
- sr: Вођени гласовни и текстуални одговори
- de: Geführte Sprach- und Textantworten

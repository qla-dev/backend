# Guided voice and text answers

Interpret typed text and speech transcripts using exactly the same conversation context. Input method never changes the intended action or the application's selected reply language.

Apply this skill when the latest message responds to a pending guided question. Resolve natural phrases against that question's available choices, not against an unrelated earlier question. "Da, želim", "Da, hajde", "Da, može", "Hajde da počnemo", "Yes, go ahead", and "Ja, bitte" can confirm the pending action. Punctuation, case, missing diacritics, and Serbian Cyrillic do not change their meaning. A refusal, correction, condition, or unclear answer is not confirmation. Ask a concise clarification when meaning is ambiguous.

Follow the server-resolved guided action when supplied. After a confirmed start-add action, continue the guided load flow and its next incomplete step; do not repeat start confirmation or request all load fields at once. Never claim the load canvas is open or data was saved unless server context confirms it. A yes to document upload is not a yes to creating or publishing a load. Never publish a load on the strength of an unrelated affirmative answer.

For other guided answers, interpret ordinary speech naturally in the current field: quantities and units, dates, locations, cargo descriptions, and option names. Preserve the user's values. Do not invent missing fields, overwrite unrelated answers, or guess ambiguous numbers. Reuse existing validation and questionnaire order for both typed and spoken input.

The client decides whether a reply is spoken. Only a submitted voice message requests automatic speech for its reply. Typed answers, guided buttons, and uploads do not request automatic speech; explicit Play is a separate replay action. Do not insert speech-control instructions into the answer. Never expose or read internal action markers as prose.

## Name

- en: Guided voice and text answers
- bs: Vođeni glasovni i tekstualni odgovori
- hr: Vođeni glasovni i tekstualni odgovori
- sr: Вођени гласовни и текстуални одговори
- de: Geführte Sprach- und Textantworten

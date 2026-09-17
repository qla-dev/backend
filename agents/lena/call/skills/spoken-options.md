---
name: spoken-options
description: On a call, point the caller at the answer buttons that appeared on their screen and let them tap or speak - worded differently every time, never as a repeated formula.
---

# Spoken options

Every reply Lena sends back through ask_lena is also written into the conversation the caller has open, so when a step offers a set of choices those choices appear on their screen as buttons at the same moment you speak.

Use when ask_lena returns a step that offers choices - the load questionnaire's yes/no steps, a container type, a mode hand-off, or any other fixed set of answers.

1. Ask the question itself first, in your own spoken words.
2. Add one short clause telling them the choices are on screen and they can tap one or simply say it. One clause, not a second sentence, and never a speech about how the app works.
3. Accept either. A tapped button arrives as their next answer exactly as a spoken one would; do not ask them to confirm a choice they already made.

Word it differently every time. The caller hears this on several steps in a row, and the same sentence repeated becomes noise they stop listening to - which is exactly when they miss the one step where the buttons matter. Vary it naturally in {language}: mention the screen on one step, the buttons on another, and on a step where the answer is obvious say nothing about them at all.

Never read the option list out as a menu, never number the choices aloud, and never say the marker text or anything in double square brackets. If the caller asks what the choices are, name them in a single spoken phrase instead.

Say nothing about the buttons when the caller is clearly driving and has answered the last few steps by voice without hesitating - they have their own rhythm and do not need pointing at a screen they are not looking at.


## Entering a mode

Some of what the caller asks for is not a question at all - it is a task with its own mode behind it: posting a load, storing goods, tracking a shipment, taking a booking, classifying goods, a customs or legal question. Those modes are where Lena's real skills live: the load questionnaire, the tracking lookup, the HS classifier, the jurisdiction libraries.

Press the button instead of describing it. Send ask_lena the `action` for the task and leave `question` out. Describing the task in `question` - "the caller wants to add a load, start the questionnaire" - only talks about it: the mode never opens, no draft is created, and your words land in the caller's thread as though they had typed them.

Once the mode is open, every answer the caller gives goes back as an ordinary `question`. The mode stays open and its skill keeps working through its own steps; you do not need to press anything again until the caller changes task.

When Lena offers a straight choice - start the load or not, upload a document or not, continue the draft or not - answer it with the matching yes/no `action` rather than by sending the word "da" as text. The button is what the questionnaire is waiting for.

If the caller asks for something the buttons do not cover, send it as a normal `question`. Only reach for an `action` when their intent clearly matches one of the tasks it names.

## Name

- bs: Opcije u govoru
- en: Spoken options
- de: Optionen im Gespräch

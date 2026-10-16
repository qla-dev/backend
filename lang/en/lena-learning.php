<?php

return [
    'title' => 'Learn with Lena',
    'unavailable' => 'Learning is not enabled on this server yet.',
    'hint_button' => 'Help me',
    'progress' => 'Independently verified competencies: :count/3',
    'phases' => ['assessment' => 'Initial assessment', 'lesson' => 'Lesson', 'final' => 'Final assessment'],
    'feedback' => [
        'resume' => 'Welcome! This pilot covers freight request data, documents and tracking. We start with three questions, then practise together. Your progress is saved in this conversation. Exercises do not create real records.',
        'choose' => 'Choose one of the answers below. This pilot evaluates those choices; it does not assess free-text answers or attachments.',
        'hint' => 'Here is a hint. An assisted answer is practice; final competencies require an independent answer.',
        'incorrect' => 'That answer does not resolve the task. Review the lesson or request a hint, then try again. This attempt is recorded as assisted practice.',
        'assessment_incorrect' => 'We will practise this topic during the lessons. Continue with the next task.',
        'correct' => 'Correct. Continue with the next task.',
        'repeat' => 'Some final answers needed help. Repeat the final assessment independently to verify all three competencies.',
    ],
    'completed' => 'You completed the introductory pilot and independently answered all three final questions. Verified: freight request data, checking documents, and identifying a shipment for tracking. This is an internal learning result, not professional certification. Next: practise in the application with your mentor.',
    /* Archived fixed questions; AI training now generates exercises.
    'lessons' => [
        'route' => [
            'teaching' => 'A usable freight request identifies pickup and delivery locations, timing, cargo and quantity. Check missing facts with the responsible person before posting; do not invent an address or deadline.',
            'question' => 'A customer sends a cargo weight, but no pickup location or date. What should you do before posting?',
            'hint' => 'The driver needs to know where and when to collect the goods. Confirm the missing information.',
            'choices' => ['a' => 'Invent a pickup address and date', 'b' => 'Ask the customer to confirm the missing location and timing', 'c' => 'Post it as complete using only the weight'],
        ],
        'documents' => [
            'teaching' => 'Document extraction helps with data entry. Verify extracted information against the original document and the shipment, especially quantities, units and references. Resolve discrepancies before using the data.',
            'question' => 'A scanned document gives a quantity that differs from the original. What is the next step?',
            'hint' => 'An automatic extraction can be wrong. Check the original and resolve the discrepancy.',
            'choices' => ['a' => 'Check the original and correct or clarify the discrepancy', 'b' => 'Always trust the extracted value', 'c' => 'Delete the document without reviewing it'],
        ],
        'tracking' => [
            'teaching' => 'Tracking begins by identifying the correct shipment. Use its exact tracking number or supported reference. Check the latest available status and its time; an old event does not guarantee the current location.',
            'question' => 'Several shipments have the same destination. How do you check the status of the right one?',
            'hint' => 'A destination alone does not uniquely identify a shipment.',
            'choices' => ['a' => 'Choose the first shipment with that destination', 'b' => 'Assume the oldest event is the current location', 'c' => 'Use the exact shipment reference and check the latest status and time'],
        ],
    ],
    */
];

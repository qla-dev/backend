<?php

return [
    'title' => 'Learn with Lena',
    'unavailable' => 'Die Schulung ist auf diesem Server noch nicht aktiviert.',
    'hint_button' => 'Hilf mir',
    'progress' => 'Selbstständig bestätigte Kompetenzen: :count/3',
    'phases' => ['assessment' => 'Einstufung', 'lesson' => 'Lektion', 'final' => 'Abschlussprüfung'],
    'feedback' => [
        'resume' => 'Willkommen! Dieser Pilot behandelt Frachtdaten, Dokumente und Sendungsverfolgung. Zuerst kommen drei Fragen, danach üben wir gemeinsam. Der Fortschritt wird in diesem Gespräch gespeichert. Übungen erstellen keine echten Geschäftsdaten.',
        'choose' => 'Wählen Sie eine der Antworten. Dieser Pilot bewertet diese Auswahl, keine Freitexte oder Anhänge.',
        'hint' => 'Hier ist ein Hinweis. Antworten mit Hilfe zählen als Übung; die Abschlussprüfung verlangt selbstständige Antworten.',
        'incorrect' => 'Diese Antwort löst die Aufgabe nicht. Lesen Sie die Lektion oder fordern Sie Hilfe an und versuchen Sie es erneut. Dieser Versuch zählt als unterstützte Übung.',
        'assessment_incorrect' => 'Dieses Thema üben wir in den Lektionen. Weiter zur nächsten Aufgabe.',
        'correct' => 'Richtig. Weiter zur nächsten Aufgabe.',
        'repeat' => 'Einige Abschlussantworten benötigten Hilfe. Wiederholen Sie die Prüfung selbstständig, um alle drei Kompetenzen zu bestätigen.',
    ],
    'completed' => 'Sie haben den Einführungspiloten abgeschlossen und alle drei Abschlussfragen selbstständig beantwortet. Bestätigt: Frachtdaten, Dokumentenprüfung und Identifikation einer Sendung. Dies ist ein internes Schulungsergebnis, keine berufliche Zertifizierung. Üben Sie als Nächstes mit Ihrem Mentor in der Anwendung.',
    /* Archived fixed questions; AI training now generates exercises.

    'lessons' => [
        'route' => [
            'teaching' => 'Eine brauchbare Frachtanfrage enthält Abhol- und Lieferorte, Termine, Ware und Menge. Klären Sie fehlende Angaben vor der Veröffentlichung; erfinden Sie keine Adresse oder Frist.',
            'question' => 'Der Kunde sendet das Gewicht, aber keinen Abholort oder Termin. Was tun Sie vor der Veröffentlichung?',
            'hint' => 'Der Fahrer muss wissen, wo und wann er die Ware abholen soll.',
            'choices' => ['a' => 'Adresse und Termin erfinden', 'b' => 'Den Kunden um Bestätigung von Ort und Termin bitten', 'c' => 'Die Anfrage nur mit dem Gewicht als vollständig veröffentlichen'],
        ],
        'documents' => [
            'teaching' => 'Die Datenextraktion erleichtert die Eingabe. Prüfen Sie Angaben anhand des Originals und der Sendung, insbesondere Mengen, Einheiten und Referenzen. Klären Sie Abweichungen vor der Verwendung.',
            'question' => 'Die extrahierte Menge weicht vom Original ab. Was ist der nächste Schritt?',
            'hint' => 'Automatische Extraktion kann Fehler enthalten. Prüfen Sie das Original.',
            'choices' => ['a' => 'Das Original prüfen und die Abweichung korrigieren oder klären', 'b' => 'Immer dem extrahierten Wert vertrauen', 'c' => 'Das Dokument ungeprüft löschen'],
        ],
        'tracking' => [
            'teaching' => 'Identifizieren Sie zuerst die richtige Sendung anhand ihrer genauen Trackingnummer oder unterstützten Referenz. Prüfen Sie den letzten verfügbaren Status und Zeitpunkt. Ein altes Ereignis garantiert nicht den aktuellen Standort.',
            'question' => 'Mehrere Sendungen haben dasselbe Ziel. Wie prüfen Sie die richtige Sendung?',
            'hint' => 'Das Ziel allein identifiziert keine einzelne Sendung.',
            'choices' => ['a' => 'Die erste Sendung zu diesem Ziel wählen', 'b' => 'Das älteste Ereignis als aktuellen Standort ansehen', 'c' => 'Die genaue Referenz verwenden und letzten Status und Zeitpunkt prüfen'],
        ],
    ],

    */
];

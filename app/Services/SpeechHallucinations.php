<?php

namespace App\Services;

/**
 * Strips the phrases Whisper invents when it is handed silence.
 *
 * Whisper was trained on subtitled video, so when a stretch of audio carries no speech - a pause, a
 * cab rumble, the office ambience bleeding back through the speaker - it falls back on what those
 * subtitles ended with: a sign-off thanking you for watching the channel. It arrives as ordinary
 * transcribed text, with no marker to say it was invented, and it is convincing enough to be saved
 * into a load draft as if the driver had said it.
 *
 * So it is cut here, at the point transcribed speech enters the app. Only sign-offs are listed:
 * these are things nobody dictating a shipment ever says, which is what makes them safe to remove.
 * Words that are ordinary freight vocabulary - "prijevoz", "prijevod" - are deliberately absent,
 * however often they appear in subtitle credits.
 */
class SpeechHallucinations
{
    /**
     * Matched case-insensitively, standing alone or trailing real speech, which is how they arrive:
     * "Šta bolan uzimaš, kao tri transporta? Hvala što pratite kanal!"
     */
    private const PHRASES = [
        // Bosnian, Croatian, Serbian
        'hvala (vam )?(što|sto) pratite kanal',
        'hvala (vam )?na (gledanju|pra(ć|c)enju)',
        'hvala (vam )?(što|sto) ste gledali',
        'pretplatite se( na kanal)?',
        // English
        'thanks? (you )?for watching',
        'please subscribe',
        'subscribe to (my|the) channel',
        // German
        'vielen dank (für|fuer)(\'?s)? zuschauen',
        'danke (für|fuer)(\'?s)? zuschauen',
        'untertitel (von|im auftrag des)',
    ];

    /** The speech with invented sign-offs removed, or '' when nothing real was left. */
    public static function strip(string $text): string
    {
        $cleaned = $text;

        foreach (self::PHRASES as $phrase) {
            // Anchored to a sentence boundary, so a phrase is only cut where it starts a sentence
            // of its own - never mid-sentence, where the same words could be something meant.
            $pattern = '/(^|[.!?…]|\n)\s*'.$phrase.'[^.!?…\n]*([.!?…]+|$)/iu';
            $result = preg_replace($pattern, '$1', $cleaned);
            if (is_string($result)) $cleaned = $result;
        }

        $cleaned = trim((string) preg_replace('/\s+/u', ' ', $cleaned));

        // A turn that was nothing but a hallucination leaves nothing worth keeping.
        return $cleaned === '' || preg_match('/^[\p{P}\s]+$/u', $cleaned) === 1 ? '' : $cleaned;
    }
}

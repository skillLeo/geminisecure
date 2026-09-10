/**
 * The settings sub-navigation's copy, shared by the four screens that draw it.
 *
 * ONE SENTENCE IN ONE PLACE. Boards 21, 22, 23 and 24 all draw the same
 * seven-item column, and `Settings::sections()` sends the same seven items to
 * each of them with one marked current. Three of the seven have no screens yet,
 * and each of those owes the reader a reason — so the reason would otherwise be
 * written out four times and be free to drift three ways the first time one of
 * them was reworded.
 *
 * A DISTINCT REASON PER ITEM, not one shared "coming soon". Arrears does the
 * same thing for its own unbuilt tabs and says why: three copies of one sentence
 * do not tell an administrator which of the three is nearest, which is the only
 * thing they can act on. Each of these names what the section is waiting on.
 *
 * Keyed by the section key the service sends, so a section renamed there does
 * not silently lose its reason here — an unknown key falls back to a sentence
 * that is still true rather than to `undefined`, which would draw a control that
 * is inert and says nothing.
 */
export const SECTION_PENDING = {
    notifications:
        'Not built yet — notification defaults decide which of several hundred households is emailed, ' +
        'texted or left alone for every kind of estate notice, so it needs the channel list and the ' +
        'per-notice override before it needs a screen. Defaults nobody can see the consequences of are ' +
        'worse than no defaults.',

    billing:
        "Not built yet — the subscription, its plan and its invoices are Gemini Security's records of " +
        'this estate as a client, and they are read and settled in the Gemini Console today. Showing ' +
        'them here means agreeing first on what a committee may change about their own bill, which is a ' +
        'commercial decision rather than a screen.',

    privacy:
        'Not built yet — data and privacy covers a resident asking what this estate holds about them and ' +
        'asking for it to be removed, and both are answers the platform has to be able to give under ' +
        'oath. A screen that offered an export or an erasure it could not actually complete would be ' +
        'worse than one that is honestly absent.',
}

/**
 * The reason for a section whose key this file has not been taught.
 *
 * Loud enough to be true and quiet enough not to claim knowledge it does not
 * have: the item is real, it came from the server, and its screens are not here.
 */
export const SECTION_PENDING_FALLBACK =
    'Not built yet — this settings section is part of the console and its screens are still being delivered.'

/** The sentence a pending section shows, whichever of the two applies. */
export function pendingReason(key) {
    return SECTION_PENDING[key] ?? SECTION_PENDING_FALLBACK
}

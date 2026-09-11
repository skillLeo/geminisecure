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
    /*
     * EMPTY, AND THAT IS THE NEWS. Notification defaults, Billing & subscription
     * and Data & privacy each carried a reason here while their screens were
     * unbuilt; all three are built, and `Settings::SECTIONS` sends an href for
     * every one of the seven. Their sentences said "not built yet" about
     * screens that exist, and a reason that has gone stale is a small lie kept
     * in reserve — so they are gone rather than left for a section that no
     * longer needs them. A section added later takes its reason here.
     */
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

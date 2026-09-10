/*
 * Holiday / highlight painting for /calendar, kept out of the Blade so it can
 * be driven under real FullCalendar in a Node harness (the bug it guards
 * against is a FullCalendar lifecycle one, not a PHP one).
 *
 * FullCalendar renders background events as its own tinted rectangles. This
 * app tags the day cell itself instead — a class, the tint, the date number's
 * colour, a small label carrying the name, a tooltip — which reads like a
 * printed Malaysian calendar. paint() does that for one event; wipe() undoes
 * all of it so a re-render can start clean.
 *
 * Two gotchas paint() deals with:
 *   - eventDidMount fires more than once per event as FullCalendar re-renders,
 *     so the label is deduped by title.
 *   - showNonCurrentDates:false hides the date number on leading/trailing
 *     cells but keeps them in the DOM with `data-date`, so the cell is chosen
 *     by "has a visible number", not by date alone.
 */

/**
 * @param {import('@fullcalendar/core').EventApi} event
 * @param {Document|Element} root
 */
export function paintBgEvent(event, root = document) {
    // Two flavours of background event:
    //   - auto holidays (red, read-only, .fc-holiday-label)
    //   - user events with display_style='background' (chosen colour,
    //     clickable, .fc-user-bg-label)
    const isHoliday = !! event.extendedProps?.isHoliday;
    const isUserBg = ! isHoliday && event.display === 'background';
    if (! isHoliday && ! isUserBg) return;

    // The "real" cell for this date — belongs to the current view, not a
    // hidden neighbouring-month cell.
    const candidates = root.querySelectorAll('.fc-daygrid-day[data-date="' + event.startStr + '"]');
    const cell = Array.from(candidates).find(c => {
        if (c.classList.contains('fc-day-disabled')) return false;
        if (c.classList.contains('fc-day-other')) return false;
        const num = c.querySelector('.fc-daygrid-day-number');
        return num && num.textContent.trim() !== '';
    });
    if (! cell) return;

    const labelClass = isHoliday ? 'fc-holiday-label' : 'fc-user-bg-label';
    const cellClass = isHoliday ? 'fc-day-has-holiday' : 'fc-day-has-user-bg';
    const textHex = event.extendedProps?.textHex;

    cell.classList.add(cellClass);
    // Both types carry their tint and text colour inline from the feed, so a
    // user's red highlight renders identically to a public holiday.
    cell.style.setProperty('background-color', event.backgroundColor);

    // Inline on the date number too: a user's colour is per-event, and inline
    // beats the Sunday rose rule that would otherwise win on Sundays.
    const dayNumber = cell.querySelector('.fc-daygrid-day-number');
    if (dayNumber && textHex) {
        dayNumber.style.setProperty('color', textHex);
    }

    let label = cell.querySelector('.' + labelClass);
    if (! label) {
        label = root.ownerDocument ? root.ownerDocument.createElement('div') : root.createElement('div');
        label.className = labelClass;
        if (textHex) label.style.color = textHex;
        const frame = cell.querySelector('.fc-daygrid-day-frame');
        (frame || cell).appendChild(label);
    }
    const parts = label.textContent ? label.textContent.split(' • ') : [];
    if (! parts.includes(event.title)) {
        parts.push(event.title);
        label.textContent = parts.join(' • ');
    }

    // The tooltip aggregates every name on this cell, either type.
    const existingTitle = cell.getAttribute('title');
    const titleParts = existingTitle ? existingTitle.split(', ') : [];
    if (! titleParts.includes(event.title)) {
        titleParts.push(event.title);
        cell.setAttribute('title', titleParts.join(', '));
    }
}

/** Remove everything paint() added, so eventDidMount (or a repaint) can rebuild cleanly. */
export function wipeBgMarkup(root = document) {
    root.querySelectorAll('.fc-holiday-label, .fc-user-bg-label').forEach(el => el.remove());
    root.querySelectorAll('.fc-day-has-holiday, .fc-day-has-user-bg').forEach(cell => {
        cell.classList.remove('fc-day-has-holiday', 'fc-day-has-user-bg');
        cell.style.removeProperty('background-color');
        cell.removeAttribute('title');
        // The date number's colour is inline too, so it has to be cleared
        // here — otherwise deleting a highlight leaves its colour on the
        // number after the cell itself has gone plain.
        cell.querySelector('.fc-daygrid-day-number')?.style.removeProperty('color');
    });
}

/*global dotclear */
'use strict';

dotclear.ready(() => {
  // DOM ready and content loaded

  const msg = dotclear.getData('orphelins');

  // Items list
  const form = document.querySelector('#items-form');
  if (form) {
    for (const elt of form.querySelectorAll('.checkboxes-helpers')) {
      dotclear.checkboxesHelpers(elt, undefined, '#items-form td input[type=checkbox]', '#items-form #do-action');
    }
    dotclear.condSubmit('#items-form td input[type="checkbox"]', '#items-form #do-action');

    // Headers in cells on small screens
    dotclear.responsiveCellHeaders(form.querySelector('#items'), '#items', 1, true);

    const action = form.querySelector('#action');
    form.querySelector('#do-action')?.addEventListener('click', (event) => {
      if (action.value !== 'delete' || globalThis.confirm(msg.confirm_delete)) {
        return true;
      }
      event.preventDefault();
      return false;
    });
  }

  // Check going on: next step, as soon as the page is shown
  const step = document.querySelector('#check-step-form');
  if (step) {
    // Page shown again by the "back" button of the browser: it is an old one, which would not go on by itself
    globalThis.addEventListener('pageshow', (event) => {
      if (event.persisted) {
        globalThis.location.reload();
      }
    });

    const stop = step.querySelector('#stop');
    const next = step.querySelector('#step');
    if (next) {
      // Not needed here: the next step is sent by itself
      next.style.display = 'none';
    }
    let stopped = false;
    stop?.addEventListener('click', () => {
      stopped = true;
    });
    globalThis.setTimeout(() => {
      if (!stopped) {
        next?.click();
      }
    }, 500);
  }
});

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
    const stop = step.querySelector('#stop');
    let stopped = false;
    stop?.addEventListener('click', () => {
      stopped = true;
    });
    globalThis.setTimeout(() => {
      if (!stopped) {
        step.querySelector('#step')?.click();
      }
    }, 500);
  }
});

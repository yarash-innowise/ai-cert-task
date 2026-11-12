// Progressive enhancement only: move focus to the error summary when a form re-renders with errors.
(function () {
  'use strict';
  var summary = document.getElementById('error-summary');
  if (summary) {
    summary.focus();
  }
})();

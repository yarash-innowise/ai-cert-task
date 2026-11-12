/* Live branding preview (progressive enhancement): without JavaScript the "Preview" button does the same on the server. */
(function () {
  'use strict';
  var preview = document.getElementById('brand-preview');
  var color = document.getElementById('field-brand_color');
  var file = document.getElementById('field-logo');
  var logo = document.getElementById('brand-preview-logo');
  if (!preview || !color) { return; }

  function channels(hex) {
    return [parseInt(hex.substr(1, 2), 16), parseInt(hex.substr(3, 2), 16), parseInt(hex.substr(5, 2), 16)];
  }
  function toHex(c) {
    return '#' + c.map(function (v) { return ('0' + Math.max(0, Math.min(255, Math.round(v))).toString(16)).slice(-2); }).join('').toUpperCase();
  }
  function luminance(c) {
    var l = c.map(function (v) { v = v / 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
    return 0.2126 * l[0] + 0.7152 * l[1] + 0.0722 * l[2];
  }
  function apply(hex) {
    var c = channels(hex);
    var lum = luminance(c);
    var onWhite = 1.05 / (lum + 0.05);
    var onBlack = (lum + 0.05) / 0.05;
    var s = preview.style;
    s.setProperty('--brand-primary', hex.toUpperCase());
    s.setProperty('--brand-primary-soft', toHex(c.map(function (v) { return v + (255 - v) * 0.8; })));
    s.setProperty('--brand-primary-deep', toHex(c.map(function (v) { return v * 0.25; })));
    s.setProperty('--brand-primary-rgb', c.join(', '));
    s.setProperty('--on-brand', onWhite >= onBlack ? '#FFFFFF' : '#000000');
  }

  color.addEventListener('input', function () {
    var value = color.value.trim();
    if (/^#[0-9A-Fa-f]{6}$/.test(value)) { apply(value); }
  });

  if (file && logo && window.URL && URL.createObjectURL) {
    var objectUrl = null;
    file.addEventListener('change', function () {
      if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
      if (file.files && file.files[0]) {
        objectUrl = URL.createObjectURL(file.files[0]);
        logo.src = objectUrl;
      } else {
        logo.src = preview.getAttribute('data-saved-logo') || logo.src;
      }
    });
  }
})();

// Simple donut chart
document.addEventListener('DOMContentLoaded', function () {
  if (window.jQuery && $.fn.select2) {
    $('.js-user-select').select2({
      width: '100%',
      placeholder: function () { return $(this).data('placeholder'); },
      allowClear: true,
      minimumInputLength: 0
    });
  }

  var canvas = document.getElementById('statusChart');
  if (!canvas || !window._chartData) return;
  var d = window._chartData;
  var total = d.active + d.trial + d.suspended;
  if (!total) return;
  var ctx = canvas.getContext('2d');
  var cx = 100, cy = 100, r = 70, innerR = 45;
  var segments = [
    { value: d.active, color: '#10b981' },
    { value: d.trial, color: '#f59e0b' },
    { value: d.suspended, color: '#ef4444' },
  ];
  var startAngle = -Math.PI / 2;
  segments.forEach(function (s) {
    if (!s.value) return;
    var sweep = (s.value / total) * 2 * Math.PI;
    ctx.beginPath();
    ctx.moveTo(cx, cy);
    ctx.arc(cx, cy, r, startAngle, startAngle + sweep);
    ctx.closePath();
    ctx.fillStyle = s.color;
    ctx.fill();
    startAngle += sweep;
  });
  ctx.beginPath();
  ctx.arc(cx, cy, innerR, 0, 2 * Math.PI);
  ctx.fillStyle = '#ffffff';
  ctx.fill();
  ctx.font = 'bold 22px sans-serif';
  ctx.fillStyle = '#1e2030';
  ctx.textAlign = 'center';
  ctx.textBaseline = 'middle';
  ctx.fillText(total, cx, cy - 6);
  ctx.font = '11px sans-serif';
  ctx.fillStyle = '#6b7280';
  ctx.fillText('clients', cx, cy + 12);
});

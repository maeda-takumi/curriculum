(function () {
  'use strict';

  function degToRad(deg) {
    return (deg * Math.PI) / 180;
  }

  function createSvgEl(tag) {
    return document.createElementNS('http://www.w3.org/2000/svg', tag);
  }

  function arcPath(cx, cy, r, startDeg, endDeg) {
    var start = {
      x: cx + r * Math.cos(degToRad(startDeg)),
      y: cy + r * Math.sin(degToRad(startDeg))
    };
    var end = {
      x: cx + r * Math.cos(degToRad(endDeg)),
      y: cy + r * Math.sin(degToRad(endDeg))
    };
    var largeArc = endDeg - startDeg > 180 ? 1 : 0;

    return [
      'M', cx, cy,
      'L', start.x, start.y,
      'A', r, r, 0, largeArc, 1, end.x, end.y,
      'Z'
    ].join(' ');
  }

  function renderPie(container) {
    if (!(container instanceof Element)) {
      return;
    }

    if (container.getAttribute('data-pie-chart-rendered') === '1') {
      return;
    }
    // data-values="40,30,20,10"
    var valuesRaw = container.getAttribute('data-values') || '';
    var values = valuesRaw.split(',').map(function (v) {
      return Number(v.trim());
    }).filter(function (v) {
      return Number.isFinite(v) && v > 0;
    });

    if (!values.length) {
      return;
    }

    // 任意: data-colors="#6b35ff,#b938ff,#ff6a4d,#ffd166"
    var colorsRaw = container.getAttribute('data-colors') || '';
    var colors = colorsRaw
      ? colorsRaw.split(',').map(function (c) { return c.trim(); })
      : ['#6b35ff', '#b938ff', '#ff6a4d', '#ffd166', '#2ec4b6', '#4361ee'];

    var size = Number(container.getAttribute('data-size') || 260);
    if (!Number.isFinite(size) || size < 120) size = 260;
    var cx = size / 2;
    var cy = size / 2;
    var r = size * 0.46;

    var total = values.reduce(function (sum, v) { return sum + v; }, 0);

    var svg = createSvgEl('svg');
    svg.setAttribute('viewBox', '0 0 ' + size + ' ' + size);
    svg.setAttribute('width', String(size));
    svg.setAttribute('height', String(size));
    svg.setAttribute('role', 'img');
    svg.setAttribute('aria-label', container.getAttribute('data-label') || '円グラフ');

    var angle = -90; // 12時スタート
    values.forEach(function (v, i) {
      var sweep = (v / total) * 360;
      var nextAngle = angle + sweep;

      var path = createSvgEl('path');
      path.setAttribute('d', arcPath(cx, cy, r, angle, nextAngle));
      path.setAttribute('fill', colors[i % colors.length]);
      svg.appendChild(path);

      angle = nextAngle;
    });

    // 中心白丸（ドーナツ化したい時）
    if (container.getAttribute('data-donut') === '1') {
      var hole = createSvgEl('circle');
      hole.setAttribute('cx', String(cx));
      hole.setAttribute('cy', String(cy));
      hole.setAttribute('r', String(r * 0.52));
      hole.setAttribute('fill', '#fff');
      svg.appendChild(hole);
    }

    container.innerHTML = '';
    container.appendChild(svg);
    container.setAttribute('data-pie-chart-rendered', '1');
  }

  function boot() {
    var nodes = document.querySelectorAll('[data-pie-chart]');
    if (!nodes.length) {
      return;
    }
    nodes.forEach(renderPie);
  }

  function safeBoot() {
    window.requestAnimationFrame(function () {
      window.setTimeout(boot, 0);
    });
  }

  if (document.readyState === 'complete') {
    safeBoot();
  } else {
    window.addEventListener('load', safeBoot, { once: true });
  }
})();

/* ============================================================
   🎣 Пасхалка: «Рибалка на Світязі»
   Активація:
     - Клавіатура: набрати "fish", "світязь" або "svityaz"
     - Мобілка: 5 швидких тапів по футеру
   v2 – повний рефактор, SVG-риби, мобілка
   ============================================================ */
(function () {
  'use strict';

  /* ═══════════ TRIGGER ═══════════ */
  const TRIGGERS = ['fish', 'світязь', 'svityaz'];
  let buf = '';

  document.addEventListener('keydown', function (e) {
    if (document.querySelector('.sg-overlay')) return;
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.isContentEditable) return;
    buf += e.key.toLowerCase();
    if (buf.length > 30) buf = buf.slice(-30);
    for (const t of TRIGGERS) {
      if (buf.endsWith(t)) { buf = ''; launchGame(); return; }
    }
  });

  // Mobile trigger: 5 fast taps on footer
  let tapCount = 0, tapTimer = 0;
  document.addEventListener('click', function (e) {
    if (document.querySelector('.sg-overlay')) return;
    const el = e.target.closest('.site-footer, .footer, footer');
    if (!el) { tapCount = 0; return; }
    tapCount++;
    clearTimeout(tapTimer);
    tapTimer = setTimeout(function () { tapCount = 0; }, 1200);
    if (tapCount >= 5) { tapCount = 0; launchGame(); }
  });

  /* ═══════════ FISH DATA ═══════════ */
  var FISH_TYPES = [
    { id: 'crucian',  name: 'Карась',  pts: 10,  speed: 1.0, w: 40, h: 20, color: '#c4a04a', belly: '#e8d890', fin: '#b08830', stripe: null },
    { id: 'perch',    name: 'Окунь',   pts: 15,  speed: 1.4, w: 38, h: 19, color: '#6a8a3a', belly: '#c0d080', fin: '#5a7a2a', stripe: '#3a5a1a' },
    { id: 'pike',     name: 'Щука',    pts: 30,  speed: 2.6, w: 56, h: 18, color: '#4a7a5a', belly: '#a0c8a0', fin: '#3a6a4a', stripe: '#2a5030' },
    { id: 'tench',    name: 'Лінь',    pts: 20,  speed: 0.8, w: 42, h: 22, color: '#5a6a3a', belly: '#9aaa7a', fin: '#4a5a2a', stripe: null },
    { id: 'roach',    name: 'Плітка',  pts: 8,   speed: 1.3, w: 32, h: 18, color: '#8a9aaa', belly: '#d0dae0', fin: '#c05040', stripe: null },
    { id: 'catfish',  name: 'Сом',     pts: 50,  speed: 0.6, w: 64, h: 26, color: '#5a5040', belly: '#a09880', fin: '#4a4030', stripe: null },
    { id: 'eel',      name: 'Вугор',   pts: 40,  speed: 2.0, w: 58, h: 12, color: '#3a5050', belly: '#8aa0a0', fin: '#2a4040', stripe: null },
    { id: 'crayfish', name: 'Рак',     pts: 25,  speed: 0.5, w: 36, h: 20, color: '#b04030', belly: '#d08070', fin: '#902820', stripe: null }
  ];

  var GAME_TIME = 60;

  /* ═══════════ STATE ═══════════ */
  var canvas, ctx, W, H, dpr;
  var overlay;
  var fishes, bubbles, catches, particles;
  var hook, score, timeLeft, phase; // phase: 'start' | 'play' | 'end'
  var lastTs, timerAccum;
  var animId;
  var lilies, stars, treeSeed;
  var caughtFx;
  var touchR;

  /* ═══════════ LAUNCH ═══════════ */
  function launchGame() {
    if (document.querySelector('.sg-overlay')) return;
    buildDOM();
    requestAnimationFrame(function () {
      overlay.classList.add('sg--visible');
      initCanvas();
      resetState('start');
      lastTs = performance.now();
      loop(lastTs);
    });
  }

  /* ═══════════ DOM ═══════════ */
  function buildDOM() {
    overlay = document.createElement('div');
    overlay.className = 'sg-overlay';
    overlay.innerHTML =
      '<div class="sg-container">' +
        '<canvas class="sg-canvas"></canvas>' +
        '<button class="sg-x" aria-label="Close">&times;</button>' +
        '<div class="sg-hud">' +
          '<div class="sg-hud-score">\uD83C\uDFC6 <span class="sg-val" data-id="score">0</span></div>' +
          '<div class="sg-hud-time">\u23F1 <span class="sg-val" data-id="time">' + GAME_TIME + '</span>\u0441</div>' +
        '</div>' +
        '<div class="sg-panel sg-panel--start" data-panel="start">' +
          '<h2>\uD83C\uDFA3 Рибалка на Світязі</h2>' +
          '<p>Лови рибу в найчистішому озері України!</p>' +
          '<ul class="sg-rules">' +
            '<li>\uD83D\uDC46 Тапни по воді — закинь вудку</li>' +
            '<li>\uD83D\uDC1F Тапни по рибі біля гачка — лови!</li>' +
            '<li>\u23F1 У тебе ' + GAME_TIME + ' секунд</li>' +
          '</ul>' +
          '<button class="sg-btn sg-btn--play">Грати!</button>' +
          '<small class="sg-hint">ESC — вийти</small>' +
        '</div>' +
        '<div class="sg-panel sg-panel--end" data-panel="end" hidden>' +
          '<h2>\uD83C\uDFC1 Час вийшов!</h2>' +
          '<div class="sg-result-score">0</div>' +
          '<div class="sg-result-label">очок</div>' +
          '<div class="sg-result-list"></div>' +
          '<div class="sg-end-btns">' +
            '<button class="sg-btn sg-btn--play">Ще раз</button>' +
            '<button class="sg-btn sg-btn--quit">Вийти</button>' +
          '</div>' +
        '</div>' +
        '<div class="sg-caught-strip"></div>' +
      '</div>';

    document.body.appendChild(overlay);

    canvas = overlay.querySelector('.sg-canvas');
    ctx = canvas.getContext('2d');

    overlay.querySelector('.sg-x').onclick = destroy;
    var playBtns = overlay.querySelectorAll('.sg-btn--play');
    for (var i = 0; i < playBtns.length; i++) {
      playBtns[i].onclick = function () { startPlay(); };
    }
    var quitBtn = overlay.querySelector('.sg-btn--quit');
    if (quitBtn) quitBtn.onclick = destroy;

    canvas.addEventListener('pointerdown', onPointer);
    canvas.addEventListener('touchstart', function (e) { e.preventDefault(); }, { passive: false });
    window.addEventListener('resize', onResize);
    document.addEventListener('keydown', onKey);
  }

  function destroy() {
    cancelAnimationFrame(animId);
    phase = 'dead';
    overlay.classList.remove('sg--visible');
    window.removeEventListener('resize', onResize);
    document.removeEventListener('keydown', onKey);
    setTimeout(function () { if (overlay && overlay.parentNode) overlay.remove(); }, 400);
  }

  function onKey(e) { if (e.key === 'Escape') destroy(); }

  /* ═══════════ CANVAS ═══════════ */
  function initCanvas() {
    dpr = Math.min(window.devicePixelRatio || 1, 2);
    onResize();
  }

  function onResize() {
    if (!canvas) return;
    var rect = canvas.getBoundingClientRect();
    W = rect.width;
    H = rect.height;
    if (W < 1 || H < 1) return;
    canvas.width = W * dpr;
    canvas.height = H * dpr;
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    touchR = W < 500 ? 45 : 28;
    generateScenery();
  }

  /* ═══════════ STATE ═══════════ */
  function wl() { return H * 0.36; }

  function resetState(p) {
    phase = p;
    fishes = [];
    bubbles = [];
    particles = [];
    catches = [];
    score = 0;
    timeLeft = GAME_TIME;
    timerAccum = 0;
    caughtFx = null;
    hook = { x: W / 2, depth: wl(), targetDepth: wl(), state: 'idle' };
    generateScenery();
    spawnFish(6);
    updateHUD();
    showPanel(p);
  }

  function startPlay() {
    resetState('play');
    showPanel(null);
    overlay.querySelector('.sg-caught-strip').innerHTML = '';
  }

  function endPlay() {
    phase = 'end';
    var panel = overlay.querySelector('[data-panel="end"]');
    panel.querySelector('.sg-result-score').textContent = score;
    var list = panel.querySelector('.sg-result-list');
    if (catches.length) {
      var cnt = {};
      catches.forEach(function (n) { cnt[n] = (cnt[n] || 0) + 1; });
      list.innerHTML = Object.entries(cnt).map(function (entry) {
        var name = entry[0], c = entry[1];
        var ft = FISH_TYPES.find(function (f) { return f.name === name; });
        return '<span class="sg-fish-tag">' + name + ' \u00D7' + c + ' <small>(+' + ((ft ? ft.pts : 0) * c) + ')</small></span>';
      }).join('');
    } else {
      list.innerHTML = '<p>Жодної рибки \uD83D\uDE22 Спробуй ще!</p>';
    }
    showPanel('end');
  }

  function showPanel(id) {
    var panels = overlay.querySelectorAll('.sg-panel');
    for (var i = 0; i < panels.length; i++) {
      panels[i].hidden = panels[i].dataset.panel !== id;
    }
    overlay.querySelector('.sg-hud').style.display = (id === null || id === undefined) ? '' : 'none';
  }

  function updateHUD() {
    var s = overlay.querySelector('[data-id="score"]');
    var t = overlay.querySelector('[data-id="time"]');
    if (s) s.textContent = score;
    if (t) {
      t.textContent = Math.max(0, Math.ceil(timeLeft));
      t.parentElement.classList.toggle('sg-warn', timeLeft <= 10);
    }
  }

  /* ═══════════ INPUT ═══════════ */
  function onPointer(e) {
    if (phase !== 'play') return;
    var rect = canvas.getBoundingClientRect();
    var mx = e.clientX - rect.left;
    var my = e.clientY - rect.top;

    // Try catch fish near hook
    if (hook.state === 'waiting' || hook.state === 'sinking') {
      for (var i = fishes.length - 1; i >= 0; i--) {
        var f = fishes[i];
        var dx = f.x - hook.x;
        var dy = f.y - hook.depth;
        var dist = Math.sqrt(dx * dx + dy * dy);
        if (dist < touchR + f.w * 0.5) {
          doCatch(f, i);
          return;
        }
      }
    }

    // Tap directly on fish if hook near
    for (var j = fishes.length - 1; j >= 0; j--) {
      var ff = fishes[j];
      var ddx = ff.x - mx;
      var ddy = ff.y - my;
      var dd = Math.sqrt(ddx * ddx + ddy * ddy);
      if (dd < touchR + ff.w * 0.4) {
        var hd = Math.sqrt(Math.pow(ff.x - hook.x, 2) + Math.pow(ff.y - hook.depth, 2));
        if (hd < 90 + ff.w) {
          doCatch(ff, j);
          return;
        }
      }
    }

    // Cast
    if (my > wl() - 10) {
      hook.x = mx;
      hook.targetDepth = Math.min(my, H - 20);
      hook.depth = wl();
      hook.state = 'sinking';
      addBubbles(mx, wl(), 4);
    }
  }

  function doCatch(fish, idx) {
    score += fish.pts;
    catches.push(fish.name);
    fishes.splice(idx, 1);

    for (var i = 0; i < 10; i++) {
      particles.push({
        x: fish.x, y: fish.y,
        vx: (Math.random() - 0.5) * 4,
        vy: (Math.random() - 0.5) * 4,
        r: 2 + Math.random() * 3,
        life: 1,
        color: fish.color
      });
    }
    addBubbles(fish.x, fish.y, 6);
    caughtFx = { text: fish.name + ' +' + fish.pts, x: fish.x, y: fish.y, life: 2 };

    var strip = overlay.querySelector('.sg-caught-strip');
    var tag = document.createElement('span');
    tag.className = 'sg-strip-fish';
    tag.textContent = fish.name;
    strip.appendChild(tag);

    hook.state = 'reeling';
    updateHUD();
    setTimeout(function () { spawnFish(1); }, 800);
  }

  /* ═══════════ SPAWN ═══════════ */
  function spawnFish(n) {
    for (var i = 0; i < n; i++) {
      var tmpl = FISH_TYPES[Math.floor(Math.random() * FISH_TYPES.length)];
      var dir = Math.random() > 0.5 ? 1 : -1;
      fishes.push({
        id: tmpl.id, name: tmpl.name, pts: tmpl.pts, speed: tmpl.speed,
        w: tmpl.w, h: tmpl.h, color: tmpl.color, belly: tmpl.belly,
        fin: tmpl.fin, stripe: tmpl.stripe,
        x: dir > 0 ? -tmpl.w : W + tmpl.w,
        y: wl() + 25 + Math.random() * (H - wl() - 60),
        vx: dir * tmpl.speed * (0.7 + Math.random() * 0.6),
        phase: Math.random() * Math.PI * 2,
        tailPhase: Math.random() * Math.PI * 2,
        wobble: 0.3 + Math.random() * 0.4
      });
    }
  }

  function addBubbles(x, y, n) {
    for (var i = 0; i < n; i++) {
      bubbles.push({
        x: x + (Math.random() - 0.5) * 16,
        y: y,
        r: 1.5 + Math.random() * 3,
        vy: -0.4 - Math.random() * 1.2,
        life: 1
      });
    }
  }

  /* ═══════════ SCENERY ═══════════ */
  function generateScenery() {
    stars = [];
    for (var i = 0; i < 50; i++) {
      stars.push({ x: Math.random() * W, y: Math.random() * wl() * 0.65, r: 0.4 + Math.random() * 1.5, p: Math.random() * Math.PI * 2 });
    }
    lilies = [];
    var lc = Math.max(2, Math.floor(W / 220));
    for (var j = 0; j < lc; j++) {
      lilies.push({ x: 50 + Math.random() * (W - 100), size: 10 + Math.random() * 8, phase: Math.random() * Math.PI * 2, flower: Math.random() > 0.4 });
    }
    treeSeed = [];
    var tc = Math.max(6, Math.floor(W / 50));
    for (var k = 0; k < tc; k++) {
      treeSeed.push({ x: (k / tc) * W + Math.sin(k * 7.3) * 15, h: 25 + Math.abs(Math.sin(k * 4.1)) * 45, w: 8 + Math.abs(Math.sin(k * 2.7)) * 10 });
    }
  }

  /* ═══════════ GAME LOOP ═══════════ */
  function loop(ts) {
    if (phase === 'dead') return;
    var dt = Math.min((ts - lastTs) / 1000, 0.1);
    lastTs = ts;

    if (phase === 'play') {
      timerAccum += dt;
      while (timerAccum >= 1) {
        timerAccum -= 1;
        timeLeft--;
        updateHUD();
        if (timeLeft > 0 && timeLeft % 4 === 0 && fishes.length < 10) spawnFish(1);
        if (timeLeft <= 0) { endPlay(); break; }
      }
    }

    update(dt, ts);
    draw(ts);
    animId = requestAnimationFrame(loop);
  }

  /* ═══════════ UPDATE ═══════════ */
  function update(dt, ts) {
    fishes.forEach(function (f) {
      f.x += f.vx * 60 * dt;
      f.phase += dt * 2.5;
      f.tailPhase += dt * 8;
      f.y += Math.sin(f.phase) * f.wobble * dt * 30;
      if (f.vx > 0 && f.x > W + f.w + 10) f.x = -f.w - 10;
      if (f.vx < 0 && f.x < -f.w - 10) f.x = W + f.w + 10;
      f.y = Math.max(wl() + 15, Math.min(H - 15, f.y));
    });

    if (hook.state === 'sinking') {
      hook.depth += (hook.targetDepth - hook.depth) * 4 * dt;
      if (Math.abs(hook.depth - hook.targetDepth) < 2) {
        hook.depth = hook.targetDepth;
        hook.state = 'waiting';
      }
    }
    if (hook.state === 'reeling') {
      hook.depth -= 250 * dt;
      if (hook.depth <= wl()) {
        hook.depth = wl();
        hook.state = 'idle';
      }
    }

    bubbles.forEach(function (b) { b.y += b.vy * 50 * dt; b.x += Math.sin(b.y * 0.3) * dt * 8; b.life -= dt * 0.6; });
    bubbles = bubbles.filter(function (b) { return b.life > 0; });

    particles.forEach(function (p) { p.x += p.vx * 50 * dt; p.y += p.vy * 50 * dt; p.vy += 2 * dt; p.life -= dt * 1.2; });
    particles = particles.filter(function (p) { return p.life > 0; });

    if (caughtFx) {
      caughtFx.y -= 35 * dt;
      caughtFx.life -= dt;
      if (caughtFx.life <= 0) caughtFx = null;
    }
  }

  /* ═══════════ DRAW ═══════════ */
  function draw(ts) {
    if (!W || !H) return;
    ctx.clearRect(0, 0, W, H);
    var wy = wl();
    drawSky(ts, wy);
    drawTrees(wy);
    drawWater(ts, wy);
    drawPlants(ts);
    drawLilies(ts, wy);
    drawFishes(ts);
    drawHook(wy);
    drawBubbles();
    drawParticles();
    drawCaughtFx();
    drawHint(wy);
  }

  function drawSky(ts, wy) {
    var g = ctx.createLinearGradient(0, 0, 0, wy);
    g.addColorStop(0, '#0f1b2d');
    g.addColorStop(1, '#1a3a4a');
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, W, wy);
    stars.forEach(function (s) {
      ctx.globalAlpha = 0.3 + 0.7 * Math.abs(Math.sin(ts / 1200 + s.p));
      ctx.fillStyle = '#fff';
      ctx.beginPath();
      ctx.arc(s.x, s.y, s.r, 0, Math.PI * 2);
      ctx.fill();
    });
    ctx.globalAlpha = 1;
    var mx = W * 0.8, my = wy * 0.22;
    ctx.save();
    ctx.shadowColor = '#ffe8a0';
    ctx.shadowBlur = 25;
    ctx.fillStyle = '#ffedb0';
    ctx.beginPath();
    ctx.arc(mx, my, 18, 0, Math.PI * 2);
    ctx.fill();
    ctx.restore();
    ctx.globalAlpha = 0.08;
    ctx.fillStyle = '#ffe8a0';
    for (var i = 0; i < 6; i++) {
      ctx.fillRect(mx - 4 - i * 3, wy + 8 + i * 14, 8 + i * 6, 3);
    }
    ctx.globalAlpha = 1;
  }

  function drawTrees(wy) {
    ctx.fillStyle = '#081810';
    treeSeed.forEach(function (t) {
      ctx.fillRect(t.x - 1.5, wy - t.h, 3, t.h);
      ctx.beginPath();
      ctx.moveTo(t.x, wy - t.h - 15);
      ctx.lineTo(t.x - t.w, wy - t.h + 8);
      ctx.lineTo(t.x + t.w, wy - t.h + 8);
      ctx.closePath();
      ctx.fill();
      ctx.beginPath();
      ctx.moveTo(t.x, wy - t.h - 5);
      ctx.lineTo(t.x - t.w * 0.8, wy - t.h + 18);
      ctx.lineTo(t.x + t.w * 0.8, wy - t.h + 18);
      ctx.closePath();
      ctx.fill();
    });
  }

  function drawWater(ts, wy) {
    var g = ctx.createLinearGradient(0, wy, 0, H);
    g.addColorStop(0, '#0e4f5e');
    g.addColorStop(0.5, '#1a6b7a');
    g.addColorStop(1, '#0a2a38');
    ctx.fillStyle = g;
    ctx.fillRect(0, wy, W, H - wy);
    ctx.strokeStyle = 'rgba(255,255,255,0.06)';
    ctx.lineWidth = 1;
    for (var r = 0; r < 4; r++) {
      ctx.beginPath();
      for (var x = 0; x < W; x += 3) {
        var yy = wy + 4 + r * 16 + Math.sin(x / 35 + ts / 900 + r * 1.5) * 2;
        x === 0 ? ctx.moveTo(x, yy) : ctx.lineTo(x, yy);
      }
      ctx.stroke();
    }
    ctx.globalAlpha = 0.25;
    ctx.fillStyle = '#c2a87d';
    ctx.beginPath();
    ctx.moveTo(0, H);
    for (var xx = 0; xx <= W; xx += 25) ctx.lineTo(xx, H - 8 - Math.sin(xx / 45) * 5);
    ctx.lineTo(W, H);
    ctx.fill();
    ctx.globalAlpha = 1;
  }

  function drawPlants(ts) {
    var pc = Math.max(4, Math.floor(W / 110));
    for (var i = 0; i < pc; i++) {
      var px = (i + 0.5) / pc * W + Math.sin(i * 5.1) * 25;
      for (var b = 0; b < 3; b++) {
        var bh = 18 + Math.abs(Math.sin(i * 3 + b)) * 28;
        var sway = Math.sin(ts / 1500 + i + b) * 8;
        ctx.beginPath();
        ctx.moveTo(px + b * 5, H - 6);
        ctx.quadraticCurveTo(px + sway + b * 5, H - bh * 0.6, px + sway * 1.3 + b * 5, H - bh);
        ctx.strokeStyle = 'rgba(30,100,50,0.35)';
        ctx.lineWidth = 2;
        ctx.stroke();
      }
    }
  }

  function drawLilies(ts, wy) {
    lilies.forEach(function (l) {
      var by = wy + Math.sin(ts / 700 + l.phase) * 2;
      ctx.fillStyle = '#2a7a30';
      ctx.beginPath();
      ctx.ellipse(l.x, by, l.size, l.size * 0.45, 0, 0.2, Math.PI * 1.7);
      ctx.fill();
      if (l.flower) {
        ctx.fillStyle = '#f0e0f0';
        for (var p = 0; p < 5; p++) {
          var a = (p / 5) * Math.PI * 2 - Math.PI / 2;
          ctx.beginPath();
          ctx.ellipse(l.x + Math.cos(a) * 4, by - 3 + Math.sin(a) * 2, 3, 5, a, 0, Math.PI * 2);
          ctx.fill();
        }
        ctx.fillStyle = '#e8c030';
        ctx.beginPath();
        ctx.arc(l.x, by - 3, 2.5, 0, Math.PI * 2);
        ctx.fill();
      }
    });
  }

  /* ═══════════ SVG-STYLE FISH ═══════════ */
  function drawFishes(ts) {
    fishes.forEach(function (f) {
      var dir = f.vx > 0 ? 1 : -1;
      var tail = Math.sin(f.tailPhase) * 0.3;
      ctx.save();
      ctx.translate(f.x, f.y);
      ctx.scale(dir, 1);
      var hw = f.w * 0.5;
      var hh = f.h * 0.5;

      if (f.id === 'eel') drawEel(f, hw, hh, tail, ts);
      else if (f.id === 'crayfish') drawCrayfish(f, hw, hh, tail);
      else if (f.id === 'pike') drawPike(f, hw, hh, tail);
      else if (f.id === 'catfish') drawCatfish(f, hw, hh, tail);
      else drawGenericFish(f, hw, hh, tail);

      ctx.restore();

      // Highlight near hook
      if ((hook.state === 'waiting' || hook.state === 'sinking') && phase === 'play') {
        var dx = f.x - hook.x;
        var dy = f.y - hook.depth;
        var dist = Math.sqrt(dx * dx + dy * dy);
        if (dist < touchR + f.w * 0.5) {
          ctx.save();
          ctx.strokeStyle = 'rgba(255,255,100,0.6)';
          ctx.lineWidth = 2;
          ctx.setLineDash([4, 4]);
          ctx.beginPath();
          ctx.arc(f.x, f.y, Math.max(f.w, f.h) * 0.55 + 4, 0, Math.PI * 2);
          ctx.stroke();
          ctx.setLineDash([]);
          ctx.fillStyle = 'rgba(255,255,255,0.9)';
          ctx.font = 'bold ' + (W < 400 ? 10 : 12) + 'px sans-serif';
          ctx.textAlign = 'center';
          ctx.fillText(f.name + ' +' + f.pts, f.x, f.y - Math.max(f.w, f.h) * 0.5 - 8);
          ctx.restore();
        }
      }
    });
  }

  /* --- карась / окунь / лінь / плітка --- */
  function drawGenericFish(f, hw, hh, tail) {
    // Tail
    ctx.fillStyle = f.fin;
    ctx.beginPath();
    ctx.moveTo(-hw * 0.7, 0);
    ctx.lineTo(-hw - 6 + tail * 5, -hh * 0.8);
    ctx.quadraticCurveTo(-hw * 0.9, 0, -hw - 6 + tail * 5, hh * 0.8);
    ctx.closePath();
    ctx.fill();
    // Body
    ctx.fillStyle = f.color;
    ctx.beginPath();
    ctx.ellipse(0, 0, hw, hh, 0, 0, Math.PI * 2);
    ctx.fill();
    // Belly
    ctx.fillStyle = f.belly;
    ctx.beginPath();
    ctx.ellipse(0, hh * 0.25, hw * 0.85, hh * 0.55, 0, 0, Math.PI);
    ctx.fill();
    // Stripes (perch)
    if (f.stripe) {
      ctx.strokeStyle = f.stripe;
      ctx.lineWidth = 1.5;
      for (var i = -2; i <= 2; i++) {
        var sx = i * (hw * 0.28);
        ctx.beginPath(); ctx.moveTo(sx, -hh * 0.7); ctx.lineTo(sx + 1, hh * 0.3); ctx.stroke();
      }
    }
    // Dorsal fin
    ctx.fillStyle = f.fin;
    ctx.globalAlpha = 0.7;
    ctx.beginPath();
    ctx.moveTo(-hw * 0.2, -hh * 0.85);
    ctx.quadraticCurveTo(hw * 0.1, -hh * 1.5, hw * 0.4, -hh * 0.85);
    ctx.closePath();
    ctx.fill();
    ctx.globalAlpha = 1;
    // Pectoral fin
    ctx.fillStyle = f.fin;
    ctx.globalAlpha = 0.5;
    ctx.beginPath();
    ctx.ellipse(hw * 0.05, hh * 0.5, hw * 0.25, hh * 0.2, 0.4, 0, Math.PI * 2);
    ctx.fill();
    ctx.globalAlpha = 1;
    // Eye
    ctx.fillStyle = '#fff';
    ctx.beginPath(); ctx.arc(hw * 0.5, -hh * 0.15, hh * 0.22, 0, Math.PI * 2); ctx.fill();
    ctx.fillStyle = '#111';
    ctx.beginPath(); ctx.arc(hw * 0.55, -hh * 0.15, hh * 0.12, 0, Math.PI * 2); ctx.fill();
    ctx.fillStyle = '#fff';
    ctx.beginPath(); ctx.arc(hw * 0.58, -hh * 0.22, hh * 0.05, 0, Math.PI * 2); ctx.fill();
    // Mouth
    ctx.strokeStyle = 'rgba(0,0,0,0.3)';
    ctx.lineWidth = 1;
    ctx.beginPath(); ctx.arc(hw * 0.8, hh * 0.05, 3, -0.3, 0.5); ctx.stroke();
    // Scales
    ctx.strokeStyle = 'rgba(255,255,255,0.08)';
    ctx.lineWidth = 0.5;
    for (var r = 0; r < 3; r++) {
      for (var c = -1; c <= 1; c++) {
        ctx.beginPath(); ctx.arc(hw * 0.15 + r * hw * 0.2, c * hh * 0.3, 4, 0, Math.PI * 2); ctx.stroke();
      }
    }
  }

  /* --- щука --- */
  function drawPike(f, hw, hh, tail) {
    ctx.fillStyle = f.fin;
    ctx.beginPath();
    ctx.moveTo(-hw * 0.75, 0);
    ctx.lineTo(-hw - 4 + tail * 6, -hh * 1.0);
    ctx.quadraticCurveTo(-hw * 0.85, 0, -hw - 4 + tail * 6, hh * 1.0);
    ctx.closePath();
    ctx.fill();
    ctx.fillStyle = f.color;
    ctx.beginPath();
    ctx.moveTo(hw, 0);
    ctx.quadraticCurveTo(hw * 0.6, -hh, -hw * 0.3, -hh * 0.8);
    ctx.lineTo(-hw * 0.8, -hh * 0.4);
    ctx.lineTo(-hw * 0.8, hh * 0.4);
    ctx.lineTo(-hw * 0.3, hh * 0.9);
    ctx.quadraticCurveTo(hw * 0.6, hh, hw, 0);
    ctx.fill();
    ctx.fillStyle = f.belly;
    ctx.beginPath();
    ctx.moveTo(hw * 0.8, hh * 0.1);
    ctx.quadraticCurveTo(0, hh * 0.8, -hw * 0.7, hh * 0.3);
    ctx.lineTo(-hw * 0.7, hh * 0.1);
    ctx.quadraticCurveTo(0, hh * 0.5, hw * 0.8, hh * 0.1);
    ctx.fill();
    ctx.fillStyle = f.stripe;
    ctx.globalAlpha = 0.4;
    for (var i = 0; i < 5; i++) {
      ctx.beginPath(); ctx.arc(-hw * 0.4 + i * hw * 0.25, Math.sin(i * 2) * hh * 0.3, 2, 0, Math.PI * 2); ctx.fill();
    }
    ctx.globalAlpha = 1;
    ctx.fillStyle = f.fin; ctx.globalAlpha = 0.6;
    ctx.beginPath();
    ctx.moveTo(-hw * 0.5, -hh * 0.7);
    ctx.quadraticCurveTo(-hw * 0.3, -hh * 1.4, -hw * 0.05, -hh * 0.7);
    ctx.closePath(); ctx.fill(); ctx.globalAlpha = 1;
    // Eye
    ctx.fillStyle = '#e8e070';
    ctx.beginPath(); ctx.arc(hw * 0.55, -hh * 0.15, hh * 0.25, 0, Math.PI * 2); ctx.fill();
    ctx.fillStyle = '#111';
    ctx.beginPath(); ctx.arc(hw * 0.6, -hh * 0.15, hh * 0.12, 0, Math.PI * 2); ctx.fill();
    // Jaw teeth
    ctx.strokeStyle = 'rgba(0,0,0,0.4)'; ctx.lineWidth = 1.5;
    ctx.beginPath(); ctx.moveTo(hw * 0.85, -hh * 0.05); ctx.lineTo(hw + 2, hh * 0.1); ctx.stroke();
    ctx.strokeStyle = '#ddd'; ctx.lineWidth = 1;
    for (var t = 0; t < 3; t++) {
      var tx = hw * 0.7 + t * 5;
      ctx.beginPath(); ctx.moveTo(tx, hh * 0.05); ctx.lineTo(tx + 1, hh * 0.15); ctx.stroke();
    }
  }

  /* --- сом --- */
  function drawCatfish(f, hw, hh, tail) {
    ctx.fillStyle = f.fin;
    ctx.beginPath();
    ctx.moveTo(-hw * 0.7, 0);
    ctx.lineTo(-hw - 3 + tail * 4, -hh * 0.9);
    ctx.quadraticCurveTo(-hw * 0.85, 0, -hw - 3 + tail * 4, hh * 0.9);
    ctx.closePath(); ctx.fill();
    ctx.fillStyle = f.color;
    ctx.beginPath(); ctx.ellipse(0, 0, hw, hh, 0, 0, Math.PI * 2); ctx.fill();
    ctx.fillStyle = f.belly;
    ctx.beginPath(); ctx.ellipse(hw * 0.1, hh * 0.2, hw * 0.7, hh * 0.6, 0, 0, Math.PI); ctx.fill();
    ctx.fillStyle = f.color;
    ctx.beginPath(); ctx.ellipse(hw * 0.4, 0, hw * 0.45, hh * 1.1, 0, 0, Math.PI * 2); ctx.fill();
    // Whiskers
    ctx.strokeStyle = 'rgba(80,60,40,0.7)'; ctx.lineWidth = 1.5;
    ctx.beginPath(); ctx.moveTo(hw * 0.7, -hh * 0.2); ctx.quadraticCurveTo(hw + 10, -hh * 0.8, hw + 18, -hh * 0.3); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(hw * 0.7, -hh * 0.1); ctx.quadraticCurveTo(hw + 12, -hh * 0.4, hw + 20, -hh * 0.1); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(hw * 0.65, hh * 0.3); ctx.quadraticCurveTo(hw + 8, hh * 0.6, hw + 14, hh * 0.35); ctx.stroke();
    // Eye
    ctx.fillStyle = '#c0a030';
    ctx.beginPath(); ctx.arc(hw * 0.6, -hh * 0.25, hh * 0.15, 0, Math.PI * 2); ctx.fill();
    ctx.fillStyle = '#111';
    ctx.beginPath(); ctx.arc(hw * 0.63, -hh * 0.25, hh * 0.08, 0, Math.PI * 2); ctx.fill();
    // Mouth
    ctx.strokeStyle = 'rgba(0,0,0,0.3)'; ctx.lineWidth = 2;
    ctx.beginPath(); ctx.arc(hw * 0.75, hh * 0.15, 5, -0.2, 0.8); ctx.stroke();
  }

  /* --- вугор --- */
  function drawEel(f, hw, hh, tail, ts) {
    var seg = 8, segLen = (hw * 2) / seg;
    ctx.lineCap = 'round'; ctx.lineJoin = 'round';
    ctx.lineWidth = hh * 1.6;
    ctx.strokeStyle = f.color;
    ctx.beginPath();
    for (var i = 0; i <= seg; i++) {
      var sx = -hw + i * segLen, sy = Math.sin(ts / 300 + i * 0.8) * hh * 0.6;
      i === 0 ? ctx.moveTo(sx, sy) : ctx.lineTo(sx, sy);
    }
    ctx.stroke();
    ctx.lineWidth = hh * 0.8; ctx.strokeStyle = f.belly; ctx.globalAlpha = 0.4;
    ctx.beginPath();
    for (var j = 0; j <= seg; j++) {
      var sx2 = -hw + j * segLen, sy2 = Math.sin(ts / 300 + j * 0.8) * hh * 0.6 + hh * 0.15;
      j === 0 ? ctx.moveTo(sx2, sy2) : ctx.lineTo(sx2, sy2);
    }
    ctx.stroke(); ctx.globalAlpha = 1;
    var headY = Math.sin(ts / 300) * hh * 0.6;
    ctx.fillStyle = f.color;
    ctx.beginPath(); ctx.arc(hw + 2, headY, hh * 0.9, 0, Math.PI * 2); ctx.fill();
    ctx.fillStyle = '#ddd';
    ctx.beginPath(); ctx.arc(hw + 5, headY - hh * 0.15, 2.5, 0, Math.PI * 2); ctx.fill();
    ctx.fillStyle = '#111';
    ctx.beginPath(); ctx.arc(hw + 5.5, headY - hh * 0.15, 1.2, 0, Math.PI * 2); ctx.fill();
  }

  /* --- рак --- */
  function drawCrayfish(f, hw, hh, tail) {
    ctx.fillStyle = f.fin;
    ctx.beginPath();
    ctx.moveTo(-hw * 0.6, 0);
    ctx.lineTo(-hw - 4, -hh * 0.7); ctx.lineTo(-hw - 8, 0); ctx.lineTo(-hw - 4, hh * 0.7);
    ctx.closePath(); ctx.fill();
    ctx.fillStyle = f.color;
    for (var i = 0; i < 4; i++) {
      ctx.beginPath(); ctx.ellipse(-hw * 0.4 + i * hw * 0.15, 0, hw * 0.12, hh * 0.4 - i * 0.5, 0, 0, Math.PI * 2); ctx.fill();
    }
    ctx.beginPath(); ctx.ellipse(hw * 0.1, 0, hw * 0.4, hh * 0.5, 0, 0, Math.PI * 2); ctx.fill();
    ctx.fillStyle = f.belly; ctx.globalAlpha = 0.4;
    ctx.beginPath(); ctx.ellipse(hw * 0.1, hh * 0.1, hw * 0.3, hh * 0.3, 0, 0, Math.PI * 2); ctx.fill();
    ctx.globalAlpha = 1;
    // Claws
    ctx.fillStyle = f.color; ctx.strokeStyle = f.fin; ctx.lineWidth = 2;
    ctx.beginPath(); ctx.moveTo(hw * 0.35, -hh * 0.3); ctx.lineTo(hw * 0.7, -hh * 0.7); ctx.stroke();
    ctx.beginPath(); ctx.ellipse(hw * 0.75, -hh * 0.75, 5, 3.5, -0.4, 0, Math.PI * 2); ctx.fill();
    ctx.beginPath(); ctx.moveTo(hw * 0.35, hh * 0.3); ctx.lineTo(hw * 0.7, hh * 0.65); ctx.stroke();
    ctx.beginPath(); ctx.ellipse(hw * 0.75, hh * 0.7, 5, 3.5, 0.4, 0, Math.PI * 2); ctx.fill();
    // Eyes
    ctx.fillStyle = '#111';
    ctx.beginPath(); ctx.arc(hw * 0.4, -hh * 0.4, 2, 0, Math.PI * 2); ctx.fill();
    ctx.beginPath(); ctx.arc(hw * 0.4, hh * 0.35, 2, 0, Math.PI * 2); ctx.fill();
    // Legs
    ctx.strokeStyle = f.fin; ctx.lineWidth = 1;
    for (var j = 0; j < 3; j++) {
      var lx = hw * 0.05 - j * hw * 0.12;
      ctx.beginPath(); ctx.moveTo(lx, -hh * 0.4); ctx.lineTo(lx - 4, -hh * 0.7 - j * 2); ctx.stroke();
      ctx.beginPath(); ctx.moveTo(lx, hh * 0.4); ctx.lineTo(lx - 4, hh * 0.7 + j * 2); ctx.stroke();
    }
    // Antennae
    ctx.strokeStyle = 'rgba(160,60,40,0.5)'; ctx.lineWidth = 0.8;
    ctx.beginPath(); ctx.moveTo(hw * 0.4, -hh * 0.2); ctx.quadraticCurveTo(hw + 6, -hh * 0.5, hw + 14, -hh * 0.15); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(hw * 0.4, hh * 0.15); ctx.quadraticCurveTo(hw + 6, hh * 0.4, hw + 14, hh * 0.1); ctx.stroke();
  }

  /* --- hook --- */
  function drawHook(wy) {
    if (hook.state === 'idle') return;
    var rx = hook.x, ry = wy - 15;
    ctx.strokeStyle = 'rgba(255,255,255,0.5)'; ctx.lineWidth = 1;
    ctx.beginPath(); ctx.moveTo(rx, ry);
    ctx.quadraticCurveTo(rx + 3, (ry + hook.depth) / 2, hook.x, hook.depth);
    ctx.stroke();
    // Float
    ctx.fillStyle = '#e03030';
    ctx.beginPath(); ctx.ellipse(rx, wy, 4, 7, 0, 0, Math.PI * 2); ctx.fill();
    ctx.fillStyle = '#fff';
    ctx.beginPath(); ctx.ellipse(rx, wy - 5, 4, 3, 0, 0, Math.PI * 2); ctx.fill();
    // Hook
    ctx.strokeStyle = '#bbb'; ctx.lineWidth = 2;
    ctx.beginPath(); ctx.arc(hook.x, hook.depth + 5, 5, 0, Math.PI, false); ctx.stroke();
    ctx.fillStyle = '#ccc';
    ctx.beginPath(); ctx.arc(hook.x - 5, hook.depth + 5, 1.5, 0, Math.PI * 2); ctx.fill();
    // Worm
    ctx.strokeStyle = '#d07060'; ctx.lineWidth = 2;
    ctx.beginPath(); ctx.moveTo(hook.x, hook.depth + 10);
    ctx.quadraticCurveTo(hook.x + 4, hook.depth + 14, hook.x - 2, hook.depth + 16);
    ctx.stroke();
  }

  function drawBubbles() {
    bubbles.forEach(function (b) {
      ctx.globalAlpha = b.life * 0.5;
      ctx.strokeStyle = 'rgba(180,220,255,0.7)'; ctx.lineWidth = 0.8;
      ctx.beginPath(); ctx.arc(b.x, b.y, b.r, 0, Math.PI * 2); ctx.stroke();
      ctx.fillStyle = 'rgba(255,255,255,0.3)';
      ctx.beginPath(); ctx.arc(b.x - b.r * 0.3, b.y - b.r * 0.3, b.r * 0.3, 0, Math.PI * 2); ctx.fill();
    });
    ctx.globalAlpha = 1;
  }

  function drawParticles() {
    particles.forEach(function (p) {
      ctx.globalAlpha = p.life;
      ctx.fillStyle = p.color;
      ctx.beginPath(); ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2); ctx.fill();
    });
    ctx.globalAlpha = 1;
  }

  function drawCaughtFx() {
    if (!caughtFx) return;
    ctx.globalAlpha = Math.min(1, caughtFx.life);
    ctx.save();
    ctx.font = 'bold ' + (W < 400 ? 15 : 20) + 'px sans-serif';
    ctx.textAlign = 'center';
    ctx.shadowColor = '#000'; ctx.shadowBlur = 5;
    ctx.fillStyle = '#ffe040';
    ctx.fillText(caughtFx.text, caughtFx.x, caughtFx.y);
    ctx.restore();
    ctx.globalAlpha = 1;
  }

  function drawHint(wy) {
    if (phase !== 'play' || hook.state !== 'idle') return;
    ctx.fillStyle = 'rgba(255,255,255,0.45)';
    ctx.font = (W < 400 ? 12 : 14) + 'px sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText('\uD83D\uDC46 Тапни по воді \u2014 закинь вудку', W / 2, wy + 28);
  }

})();

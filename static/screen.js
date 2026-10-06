const $ = id => document.getElementById(id);
let last = '', cached;
function element(tag, text, cls) { const el = document.createElement(tag); el.textContent = text; if (cls) el.className = cls; return el; }
function render(data) {
  const signature = JSON.stringify([data.week, data.today, data.revision, data.mode]);
  if (last === signature) return;
  last = signature;
  $('board').replaceChildren();
  const week = data.week;
  $('subtitle').textContent = week ? week.title : 'Aucun planning pour la semaine courante';
  $('updated').textContent = data.updated ? 'Mis à jour le ' + new Date(data.updated).toLocaleString('fr-FR') : '';
  if (!week) { $('board').append(element('div', 'Importez un fichier depuis l’administration ou sélectionnez une semaine disponible.', 'empty')); return; }
  $('board').style.setProperty('--days', week.days.length);
  for (const day of week.days) {
    const card = element('section', '', 'day' + (day.date === data.today ? ' today' : ''));
    const heading = element('div', '', 'day-heading');
    heading.append(element('h2', day.name), element('span', new Date(day.date + 'T12:00:00').toLocaleDateString('fr-FR', {day:'numeric',month:'short'})));
    card.append(heading);
    for (const route of day.routes) {
      const block = element('article', '', 'route');
      const top = element('div', '', 'route-top');
      top.append(element('span', route.number, 'route-number'), element('span', route.type, 'badge ' + (['EMB','OMR','BIO'].includes(route.type) ? route.type : '')));
      block.append(top, element('h3', route.route || route.number));
      if (route.vehicle) block.append(element('p', route.vehicle, 'vehicle'));
      if (route.driver) block.append(element('p', route.driver, 'driver'));
      const crew = [route.crew1, route.crew2].filter(Boolean).join(' · ');
      if (crew) block.append(element('p', crew, 'crew'));
      card.append(block);
    }
    $('board').append(card);
  }
  fit();
}
function fit() {
  const board = $('board');
  board.style.zoom = 1;
  if (window.innerWidth < 900) return;
  const available = window.innerHeight - document.querySelector('header').offsetHeight - document.querySelector('footer').offsetHeight - 10;
  board.style.zoom = Math.min(1, available / board.offsetHeight);
}
window.addEventListener('resize', fit);
async function refresh() {
  try {
    const response = await fetch('/api/planning', {cache:'no-store', signal:AbortSignal.timeout(4000)});
    if (!response.ok) throw Error();
    cached = await response.json(); render(cached);
    $('status').textContent = '● En direct'; $('status').className = 'connected';
  } catch {
    $('status').textContent = '● Connexion interrompue'; $('status').className = 'offline';
  }
}
function clock() { $('clock').textContent = new Date().toLocaleTimeString('fr-FR', {timeZone:'Europe/Paris',hour:'2-digit',minute:'2-digit'}); }
$('fullscreen').onclick = () => { if (document.fullscreenElement) document.exitFullscreen(); else document.documentElement.requestFullscreen().catch(() => {}); };
refresh(); clock(); setInterval(refresh, 5000); setInterval(clock, 1000);

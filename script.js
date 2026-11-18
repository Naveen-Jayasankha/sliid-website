const current = document.body.dataset.page || location.pathname.split('/').pop().replace('.html', '') || 'home';
const nav = [
  ['index.html', 'Home', 'home'], ['about.html', 'About', 'about'],
  ['membership.html', 'Membership', 'membership'], ['directory.html', 'Directory', 'directory'],
  ['events.html', 'Events', 'events'], ['news.html', 'News', 'news'],
  ['contact.html', 'Contact', 'contact']
];
const brand = `<span class="brand-emblem"><img src="assets/sliid-official-logo.webp" alt=""></span><span class="brand-name"><strong>SLIID</strong><small>Sri Lanka Institute of<br>Interior Designers</small></span>`;
const header = document.querySelector('[data-header]');
if (header) {
  header.className = 'topbar';
  header.innerHTML = `<div class="shell"><a class="wordmark" href="index.html" aria-label="Sri Lanka Institute of Interior Designers home">${brand}</a><button class="menu-toggle" aria-expanded="false" aria-controls="site-nav" aria-label="Open navigation"><span></span><span></span><span></span></button><nav class="mainnav" id="site-nav" aria-label="Main navigation">${nav.map(([href,label,key]) => `<a href="${href}"${current === key ? ' aria-current="page"' : ''}>${label}</a>`).join('')}<a class="mobile-join" href="apply.html">Join SLIID →</a></nav><a class="join-btn" href="apply.html">Join SLIID →</a></div>`;
}
const footer = document.querySelector('[data-footer]');
if (footer) {
  footer.className = 'site-footer';
  footer.innerHTML = `<div class="shell"><div class="footer-grid"><div class="footer-mark"><a class="wordmark" href="index.html">${brand}</a><div class="footer-tag">Inspire · Design · Innovate · Lead</div></div><div class="footer-col"><b>Explore</b><a href="about.html">About</a><a href="governance.html">Governance</a><a href="membership.html">Membership</a><a href="directory.html">Directory</a><a href="events.html">Events</a><a href="news.html">News</a></div><div class="footer-col"><b>Resources</b><a href="education.html">Professional learning</a><a href="publications.html">Publications</a><a href="privacy.html">Privacy</a></div><div class="footer-col"><b>Contact</b><span>601, 6th Floor, Janajaya City</span><span>Nawala Road, Rajagiriya, Sri Lanka</span><span>Visits by appointment</span><a href="contact.html">Contact the Institute</a></div></div><div class="footer-languages"><span lang="si">ශ්‍රී ලංකා අභ්‍යන්තර නිර්මාණ වේදීන්ගේ ආයතනය</span><span lang="ta">இலங்கை உள்துறை வடிவமைப்பாளர்கள் நிறுவகம்</span></div><div class="copyright"><span>© ${new Date().getFullYear()} Sri Lanka Institute of Interior Designers. All rights reserved.</span><span>Incorporated by Act of Parliament No. 2 of 1998</span></div></div>`;
}
const menuButton = document.querySelector('.menu-toggle');
const navigation = document.querySelector('#site-nav');
if (menuButton && navigation) menuButton.addEventListener('click', () => {
  const open = menuButton.getAttribute('aria-expanded') === 'true';
  menuButton.setAttribute('aria-expanded', String(!open));
  navigation.classList.toggle('open', !open);
});

const esc = value => String(value ?? '').replace(/[&<>'"]/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[ch]));
const date = value => value ? new Intl.DateTimeFormat('en-LK', {day:'numeric',month:'long',year:'numeric'}).format(new Date(value.replace(' ','T'))) : '';
const eventTime = value => value ? new Intl.DateTimeFormat('en-LK', {hour:'numeric',minute:'2-digit'}).format(new Date(value.replace(' ','T'))) : '';
const tags = value => String(value || '').split(',').map(tag => tag.trim().replace(/^#+/, '')).filter(Boolean);
const tagMarkup = value => tags(value).length ? `<div class="event-tags">${tags(value).map(tag => `<span>#${esc(tag.replace(/\s+/g, ''))}</span>`).join('')}</div>` : '';
async function api(path, options = {}) {
  if (!window.SLIID_API_BASE) throw new Error('Online submissions are temporarily unavailable.');
  const response = await fetch(`${window.SLIID_API_BASE.replace(/\/$/, '')}/api.php?resource=${path}`, options);
  const data = await response.json().catch(() => ({ok:false,message:'Invalid server response.'}));
  if (!response.ok || !data.ok) throw new Error(data.message || 'Request failed.');
  return data;
}
async function initBackend() {
  try { const cfg = await fetch('api-config.json', {cache:'no-store'}).then(r => r.ok ? r.json() : {}); window.SLIID_API_BASE = cfg.apiBase || ''; }
  catch { window.SLIID_API_BASE = ''; }
  if (!window.SLIID_API_BASE && /^(localhost|127\.0\.0\.1)$/.test(location.hostname)) {
    const distIndex = location.pathname.indexOf('/dist/');
    const projectRoot = distIndex >= 0 ? location.pathname.slice(0, distIndex) : '';
    window.SLIID_API_BASE = `${location.origin}${projectRoot}/backend`;
  }
  const connected = Boolean(window.SLIID_API_BASE);
  document.documentElement.dataset.backend = connected ? 'connected' : 'pending';
  document.querySelectorAll('[data-backend-form]').forEach(form => {
    const note = form.querySelector('.form-note'); const button = form.querySelector('button[type="submit"]');
    if (!connected) { if (button) button.disabled = true; if (note) note.textContent = 'Online submissions will open when the Institute’s secure service is connected.'; }
  });
  if (current === 'contact') {
    const form = document.querySelector('[data-backend-form="contact"]');
    if (form && connected) form.addEventListener('submit', async event => {
      event.preventDefault(); const note = form.querySelector('.form-note'); const button = form.querySelector('button'); button.disabled = true; note.textContent = 'Sending…';
      try { const result = await api('contact', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(Object.fromEntries(new FormData(form)))}); note.textContent = result.message; form.reset(); }
      catch (error) { note.textContent = error.message; } finally { button.disabled = false; }
    });
  }
  if (current === 'apply') {
    const form = document.querySelector('#membership-application');
    if (form && connected) form.addEventListener('submit', async event => {
      event.preventDefault(); const note = form.querySelector('.form-note'); const button = form.querySelector('button'); button.disabled = true; note.textContent = 'Submitting…';
      try { const result = await api('membership-applications', {method:'POST',body:new FormData(form)}); note.textContent = result.message; form.reset(); }
      catch (error) { note.textContent = error.message; } finally { button.disabled = false; }
    });
  }
  if (!connected) return;
  if (current === 'home') loadHomeEventHighlights();
  if (current === 'directory') initDirectory();
  if (current === 'news') loadNews();
  if (current === 'events') loadEvents();
  if (current === 'publications') loadPublications();
}
async function loadHomeEventHighlights() {
  const grid=document.querySelector('[data-home-event-highlights]');if(!grid)return;
  try {
    const [upcoming,recent]=await Promise.all([api('events&limit=3'),api('events&past=1&limit=3')]);
    const selected=[...upcoming.data.map(item=>({...item,event_type:'Upcoming event'})),...recent.data.map(item=>({...item,event_type:'Recent highlight'}))].slice(0,3);
    if(!selected.length){grid.innerHTML='<div class="home-event-loading"><span>More to come</span><p>New SLIID events and professional programmes will be announced here.</p><a href="events.html">Visit events →</a></div>';return;}
    grid.innerHTML=selected.map((item,index)=>`<article class="home-event-card${index===0?' featured':''}">${item.image_url?`<div class="home-event-image"><img src="${esc(item.image_url)}" alt="${esc(item.title)}"></div>`:'<div class="home-event-image home-event-fallback" aria-hidden="true">SLIID</div>'}<div class="home-event-content"><span class="home-event-type">${esc(item.event_type)}</span><p class="home-event-date">${esc(date(item.starts_at))}${item.venue?` · ${esc(item.venue)}`:''}</p><h3>${esc(item.title)}</h3>${item.summary?`<p class="home-event-summary">${esc(item.summary)}</p>`:''}<a href="events.html">View highlight →</a></div></article>`).join('');
  } catch(error) {
    grid.innerHTML=`<div class="home-event-loading"><span>Event highlights</span><p>${esc(error.message)}</p><a href="events.html">Visit events →</a></div>`;
  }
}
async function initDirectory() {
  const form = document.querySelector('.filter-bar'); const grid = document.querySelector('.member-grid'); if (!form || !grid) return;
  const load = async () => {
    const [q,category,specialisation,location] = [...form.querySelectorAll('input,select')].map(x => x.value); const params = new URLSearchParams({limit:'48'});
    if(q) params.set('q',q); if(category && !category.startsWith('All')) params.set('category',category.toLowerCase()); if(specialisation && !specialisation.startsWith('All')) params.set('specialisation',specialisation); if(location && !location.startsWith('All')) params.set('location',location);
    try { const result = await api(`members&${params}`); grid.innerHTML = result.data.length ? result.data.map(member => `<article class="member">${member.image_url ? `<img class="member-photo" src="${esc(member.image_url)}" alt="Portrait of ${esc(member.name)}">` : '<div class="member-placeholder" aria-hidden="true">SLIID</div>'}<div class="member-body"><h3>${esc(member.name)}</h3><small>${esc(member.category.replace('_',' '))}</small><p>${esc(member.membership_number)}</p><p>${esc(member.location || 'Sri Lanka')} · ${esc(member.specialisations || 'Interior design')}</p>${member.website ? `<a class="text-link" target="_blank" rel="noopener" href="${esc(member.website)}">View website →</a>` : ''}</div></article>`).join('') : '<div class="empty-state"><h3>No matching members</h3><p>Try a broader search.</p></div>'; }
    catch(error) { grid.innerHTML = `<div class="empty-state"><h3>Directory unavailable</h3><p>${esc(error.message)}</p></div>`; }
  };
  form.addEventListener('submit', event => { event.preventDefault(); load(); }); load();
}
async function loadNews() { const grid=document.querySelector('.news-grid'); if(!grid)return; try{const result=await api('news&limit=12');if(result.data.length)grid.innerHTML=result.data.map(item=>`<article class="card">${item.image_url?`<img class="card-img" src="${esc(item.image_url)}" alt="">`:''}<div class="card-body"><span class="meta">${esc(item.category)} · ${esc(date(item.published_at))}</span><h3>${esc(item.title)}</h3><p>${esc(item.excerpt||'')}</p></div></article>`).join('');}catch{} }
const eventCard = item => {
  const category=tags(item.tags)[0]||'SLIID event';const hasRegistration=Boolean(item.registration_url);const start=eventTime(item.starts_at);const end=eventTime(item.ends_at);
  return `<article class="upcoming-event-card">${item.image_url?`<div class="upcoming-poster"><img src="${esc(item.image_url)}" alt="${esc(item.title)}"></div>`:'<div class="upcoming-poster upcoming-poster-fallback" aria-hidden="true">SLIID</div>'}<div class="upcoming-card-body"><span class="upcoming-category">${esc(category)}</span><h3>${esc(item.title)}</h3><p class="upcoming-venue"><span aria-hidden="true">⌖</span>${esc(item.venue||'Venue to be confirmed')}</p><div class="upcoming-chips"><span class="date-chip">▣ ${esc(date(item.starts_at))}</span><span class="availability-chip">${hasRegistration?'Open':'Details soon'}</span></div><p class="upcoming-time"><span aria-hidden="true">◷</span>${esc(start)}${end?` – ${esc(end)}`:''}</p>${item.summary?`<p class="upcoming-summary">${esc(item.summary)}</p>`:''}<div class="upcoming-card-footer">${hasRegistration?`<a href="${esc(item.registration_url)}" target="_blank" rel="noopener">Register now</a>`:'<a class="is-disabled" href="contact.html">Enquire</a>'}</div></div></article>`;
};
const eventArchiveItem = (item, index) => {
  const images = [item.image_url, ...(item.gallery_images || [])].filter((value, position, all) => value && all.indexOf(value) === position);
  const media = images.length ? `<div class="event-media" data-event-slider><div class="event-stage"><img class="event-cover" src="${esc(images[0])}" alt="${esc(item.title)}" data-event-cover><button class="gallery-arrow gallery-prev" type="button" aria-label="Previous image" data-gallery-prev>‹</button><button class="gallery-arrow gallery-next" type="button" aria-label="Next image" data-gallery-next>›</button><span class="gallery-count" data-gallery-count>1 / ${images.length}</span></div>${images.length > 1 ? `<div class="event-gallery">${images.slice(1).map((image, imageIndex) => { const hiddenCount=images.length-5; return `<button class="event-thumb${imageIndex > 3 ? ' gallery-hidden' : ''}" type="button" data-gallery-src="${esc(image)}" data-gallery-index="${imageIndex + 1}" aria-label="Show ${esc(item.title)} image ${imageIndex + 2}"><img src="${esc(image)}" alt="">${imageIndex === 3 && hiddenCount > 0 ? `<span class="view-more">View more<small>+${hiddenCount}</small></span>` : ''}</button>`; }).join('')}</div>` : ''}<div class="gallery-data" hidden>${images.map(image => `<span data-gallery-image="${esc(image)}"></span>`).join('')}</div></div>` : '<div class="event-media event-media-placeholder" aria-hidden="true">SLIID</div>';
  const description = esc(item.description || '').replace(/\r?\n/g, '<br>');
  return `<article class="feature-split duo event-feature${index % 2 ? ' reverse' : ''}">${media}<div class="feature-copy"><div class="kicker">${esc(date(item.starts_at))}${item.venue ? ` · ${esc(item.venue)}` : ''}</div><h2>${esc(item.title)}</h2>${item.summary ? `<p class="event-summary">${esc(item.summary)}</p>` : ''}${description ? `<p class="event-description">${description}</p>` : ''}${tagMarkup(item.tags)}</div></article>`;
};
function initEventGalleries(root=document) {
  root.querySelectorAll('[data-event-slider]').forEach(slider => {
    const images=[...slider.querySelectorAll('[data-gallery-image]')].map(node=>node.dataset.galleryImage);
    const cover=slider.querySelector('[data-event-cover]');const count=slider.querySelector('[data-gallery-count]');let current=0;
    const show=index=>{if(!images.length)return;current=(index+images.length)%images.length;cover.src=images[current];count.textContent=`${current+1} / ${images.length}`;slider.querySelectorAll('[data-gallery-index]').forEach(button=>button.classList.toggle('active',Number(button.dataset.galleryIndex)===current));};
    slider.querySelector('[data-gallery-prev]')?.addEventListener('click',()=>show(current-1));slider.querySelector('[data-gallery-next]')?.addEventListener('click',()=>show(current+1));
    slider.querySelectorAll('[data-gallery-index]').forEach(button=>button.addEventListener('click',()=>show(Number(button.dataset.galleryIndex))));show(0);
  });
}
function updateEventStructuredData(items) {
  document.querySelector('#dynamic-event-schema')?.remove();
  if (!items.length) return;
  const schema=document.createElement('script');schema.id='dynamic-event-schema';schema.type='application/ld+json';schema.textContent=JSON.stringify({'@context':'https://schema.org','@type':'ItemList',itemListElement:items.map((item,index)=>({'@type':'ListItem',position:index+1,item:{'@type':'Event',name:item.title,description:item.summary||item.description||'',startDate:item.starts_at,endDate:item.ends_at||undefined,eventStatus:new Date(item.starts_at.replace(' ','T'))<new Date()?'https://schema.org/EventCompleted':'https://schema.org/EventScheduled',location:item.venue?{'@type':'Place',name:item.venue}:undefined,image:[item.image_url,...(item.gallery_images||[])].filter(Boolean),keywords:tags(item.tags).join(', ')}}))});document.head.append(schema);
}
async function loadEvents() {
  const upcomingGrid=document.querySelector('[data-events-grid]');
  const pastGrid=document.querySelector('[data-past-events-grid]');
  const structuredEvents=[];
  if(upcomingGrid) try {
    const result=await api('events&limit=12');
    if(result.data.length) upcomingGrid.innerHTML=result.data.map(item=>eventCard(item)).join('');structuredEvents.push(...result.data);
  } catch(error) {
    upcomingGrid.innerHTML=`<div class="empty-state"><h3>Events unavailable</h3><p>${esc(error.message)}</p></div>`;
  }
  if(pastGrid) try {
    const result=await api('events&past=1&limit=12');
    if(result.data.length){pastGrid.innerHTML=result.data.map(eventArchiveItem).join('');initEventGalleries(pastGrid);}structuredEvents.push(...result.data);
  } catch(error) {
    pastGrid.innerHTML=`<div class="empty-state"><h3>Event archive unavailable</h3><p>${esc(error.message)}</p></div>`;
  }
  updateEventStructuredData(structuredEvents);
}
async function loadPublications() { const grid=document.querySelector('[data-publications-grid]'); if(!grid)return; try{const result=await api('publications&limit=30');if(result.data.length)grid.innerHTML=result.data.map(item=>`<article class="card"><div class="card-body"><span class="meta">${esc(item.category)}</span><h3>${esc(item.title)}</h3><p>${esc(item.description||'')}</p>${item.file_url||item.external_url?`<a class="text-link" href="${esc(item.file_url||item.external_url)}" target="_blank" rel="noopener">Open publication →</a>`:''}</div></article>`).join('');}catch{} }
initBackend();

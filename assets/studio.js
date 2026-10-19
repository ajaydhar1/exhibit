(() => {
  'use strict';
  const sample = [
    {title:'Before the Web',era:'THE EARLY NETWORKS · 1950s—1960s',intro:'Long before websites and social feeds, the idea of connecting computers was an ambitious experiment. Scientists imagined a new kind of network—one that could share knowledge across distances.',blocks:[{type:'text',heading:'An idea before its time',body:'Computers were once isolated machines. Researchers began to imagine what would be possible if they could communicate.'},{type:'artifact',heading:'A network takes shape',body:'Early diagrams proposed connecting distant computers so information could travel between them.'}]},
    {title:'The First Connections',era:'1969—1982',intro:'In 1969, a small network linked research institutions—and a new way to communicate began to emerge.',blocks:[{type:'text',heading:'The first message',body:'The first ARPANET message was short, but the ambition behind it was enormous.'}]},
    {title:'A Common Language',era:'1983—1990',intro:'Shared protocols helped separate networks begin speaking the same language.',blocks:[{type:'text',heading:'Speaking TCP/IP',body:'A family of network protocols made communication across systems possible.'}]},
    {title:'The Web Goes Public',era:'1991—1999',intro:'Hyperlinks and browsers made a global network feel accessible to everyone.',blocks:[{type:'text',heading:'A new way to explore',body:'The World Wide Web connected documents and ideas through links.'}]},
    {title:'Always Connected',era:'2000s—Today',intro:'The internet moved from desks into pockets—and into daily life.',blocks:[{type:'text',heading:'A connected world',body:'Broadband, smartphones and online communities transformed how people communicate.'}]}
  ];
  let selected=0;
  const $ = id => document.getElementById(id);
  const esc = s => String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  function renderList(){
    $('chapter-list').innerHTML=sample.map((ch,i)=>`<button class="chapter-choice ${selected===i?'active':''}" type="button" data-chapter="${i}" ${selected===i?'aria-current="true"':''}><span class="chapter-index">${String(i+1).padStart(2,'0')}</span><span><strong>${esc(ch.title||'Untitled chapter')}</strong><small>${esc(ch.era||'New chapter')}</small></span></button>`).join('');
  }
  function renderBlocks(){
    const c=sample[selected];$('block-count').textContent=String(c.blocks.length).padStart(2,'0')+' BLOCKS';
    $('block-editor').innerHTML=c.blocks.map((b,i)=>`<div class="block-card"><div class="block-card-head"><span>${b.type==='artifact'?'✳ ARTIFACT':'≡ TEXT'} / ${String(i+1).padStart(2,'0')}</span><button type="button" data-remove="${i}" aria-label="Remove block ${i+1}">Remove</button></div><label for="block-head-${i}">Heading</label><input id="block-head-${i}" data-block="${i}" data-key="heading" maxlength="130" value="${esc(b.heading)}"><label for="block-body-${i}">${b.type==='artifact'?'Artifact context':'Paragraph'}</label><textarea id="block-body-${i}" data-block="${i}" data-key="body" rows="3" maxlength="2000">${esc(b.body)}</textarea></div>`).join('');
  }
  function renderPreview(){
    const c=sample[selected];$('pv-title').textContent=c.title||'Untitled chapter';
    $('pv-intro').textContent=c.intro||'Your opening narrative will appear here.';
    $('pv-eyebrow').textContent=`CHAPTER ${String(selected+1).padStart(2,'0')} / ${c.era||'YOUR STORY'}`;
    $('pv-blocks').innerHTML=c.blocks.map(b=>`<div class="preview-block ${b.type==='artifact'?'preview-artifact':''}">${b.type==='artifact'?'<small>FROM THE EXHIBITION</small>':''}<h4>${esc(b.heading||'Untitled')}</h4><p>${esc(b.body)}</p></div>`).join('');
  }
  function load(){const c=sample[selected];$('chapter-number').textContent='CHAPTER '+String(selected+1).padStart(2,'0');$('chapter-title').value=c.title;$('chapter-era').value=c.era;$('chapter-intro').value=c.intro;renderList();renderBlocks();renderPreview()}
  $('chapter-list').addEventListener('click',e=>{const b=e.target.closest('[data-chapter]');if(!b)return;selected=+b.dataset.chapter;load()});
  for(const [id,key] of [['chapter-title','title'],['chapter-era','era'],['chapter-intro','intro']])$(''+id).addEventListener('input',e=>{sample[selected][key]=e.target.value;renderList();renderPreview()});
  $('block-editor').addEventListener('input',e=>{if(!e.target.dataset.key)return;sample[selected].blocks[+e.target.dataset.block][e.target.dataset.key]=e.target.value;renderPreview()});
  $('block-editor').addEventListener('click',e=>{const b=e.target.closest('[data-remove]');if(!b)return;sample[selected].blocks.splice(+b.dataset.remove,1);renderBlocks();renderPreview()});
  function addBlock(type){sample[selected].blocks.push(type==='text'?{type,heading:'New section',body:'Write your story here.'}:{type,heading:'New artifact',body:'Describe why this item matters.'});renderBlocks();renderPreview();$('block-editor').lastElementChild?.scrollIntoView({behavior:'smooth',block:'nearest'})}
  $('add-text').addEventListener('click',()=>addBlock('text'));
  $('add-artifact').addEventListener('click',()=>addBlock('artifact'));
  $('add-chapter').addEventListener('click',()=>{sample.push({title:'Untitled chapter',era:'',intro:'',blocks:[]});selected=sample.length-1;load()});
  $('preview-toggle').addEventListener('click',()=>{const hidden=$('studio-layout').classList.toggle('preview-hidden');$('preview-toggle').textContent=hidden?'Show preview':'Hide preview';$('preview-toggle').setAttribute('aria-pressed',String(!hidden))});
  window.addEventListener('beforeunload',()=>{});
  load();
})();

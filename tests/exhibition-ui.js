'use strict';

const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function element(value = '') {
  const listeners = {};
  const attributes = {};
  const classes = new Set();
  return {
    value, textContent: '', style: {}, disabled: false, hidden: false,
    listeners, attributes, children: [],
    append(...children) { this.children.push(...children); },
    replaceChildren(...children) { this.children = children; },
    querySelectorAll(selector) {
      const tags = selector.split(', ').map(tag => tag.toLowerCase());
      return descendants(this).filter(child => tags.includes(child.tag));
    },
    addEventListener: (name, callback) => { listeners[name] = callback; },
    setAttribute: (name, value) => { attributes[name] = value; },
    focus() { this.focused = true; },
    setSelectionRange(start, end) { this.selectionStart = start; this.selectionEnd = end; },
    classList: {
      add: name => classes.add(name),
      remove: name => classes.delete(name),
      toggle(name, force) {
        if (force !== undefined) {
          if (force) classes.add(name);
          else classes.delete(name);
          return force;
        }
        if (classes.has(name)) { classes.delete(name); return false; }
        classes.add(name);
        return true;
      },
    },
  };
}

function createPage() {
  const ids = ['new-title', 'new-subtitle', 'new-subject', 'new-cover', 'feedback',
    'pv-title', 'pv-summary', 'pv-category', 'cover-visual', 'create-form'];
  const elements = Object.fromEntries(ids.map(id => [id, element()]));
  const button = element();
  const window = element();
  elements.feedback.textContent = 'Server validation feedback';
  elements['create-form'].reportValidity = () => true;
  elements['create-form'].querySelector = () => button;
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '..', 'assets', 'new-exhibition.js'), 'utf8'), {
    document: {getElementById: id => elements[id], querySelector: () => null},
    window, URL,
  });
  return {elements, button, window};
}

test('Creation preview preserves server errors and safely updates text/cover', () => {
  const {elements} = createPage();
  assert.equal(elements.feedback.textContent, 'Server validation feedback');
  elements['new-title'].value = '<script>A title</script>';
  elements['new-subtitle'].value = 'An introduction';
  elements['new-subject'].value = 'History';
  elements['new-cover'].value = 'https://example.test/cover.jpg';
  elements['new-title'].listeners.input();
  assert.equal(elements['pv-title'].textContent, '<script>A title</script>');
  assert.equal(elements['pv-summary'].textContent, 'An introduction');
  assert.equal(elements['pv-category'].textContent, 'HISTORY');
  assert.match(elements['cover-visual'].style.backgroundImage, /https:\/\/example\.test/);
  for (const url of ['javascript:alert(1)', 'https://user:pass@example.test/image', 'invalid']) {
    elements['new-cover'].value = url;
    elements['new-cover'].listeners.input();
    assert.equal(elements['cover-visual'].style.backgroundImage, '');
  }
});

test('Creation blocks blank titles/native validation failures, but allows native POST', () => {
  const {elements, button, window} = createPage();
  let prevented = false;
  const submit = () => elements['create-form'].listeners.submit({preventDefault() { prevented = true; }});
  submit();
  assert.equal(prevented, true);
  assert.equal(elements['new-title'].focused, true);
  assert.equal(button.disabled, false);
  elements['new-title'].value = 'A saved idea';
  prevented = false;
  elements['create-form'].reportValidity = () => false;
  submit();
  assert.equal(prevented, true);
  elements['create-form'].reportValidity = () => true;
  prevented = false;
  submit();
  assert.equal(prevented, false);
  assert.equal(button.disabled, true);
  assert.match(elements.feedback.textContent, /Saving/);
  window.listeners.pageshow();
  assert.equal(button.disabled, false);
});

function studioPage(state = 'saved', chapters = [], fetchImplementation = () => new Promise(() => {})) {
  const ids = ['preview-toggle', 'studio-layout', 'studio-preview', 'studio-form', 'save-status',
    'save-draft', 'exhibition-title', 'exhibition-summary', 'exhibition-subject', 'exhibition-cover',
    'pv-title', 'pv-intro', 'pv-eyebrow', 'pv-category', 'preview-art', 'preview-orbit', 'project-title',
    'exhibition-subtitle', 'exhibition-description', 'exhibition-chapters_json', 'pv-blocks',
    'content-error', 'edit-details', 'details-editor', 'content-editor', 'editor-title', 'chapter-list', 'add-chapter',
    'title-error', 'summary-error', 'subject-error', 'cover-error', 'subtitle-error', 'description-error', 'revision'];
  const elements = Object.fromEntries(ids.map(id => [id, element()]));
  const baseline = {title: 'Saved title', summary: 'Saved intro', subject: 'History', cover: '',
    subtitle: '', description: '', chapters_json: JSON.stringify(chapters)};
  for (const [name, value] of Object.entries(baseline)) elements[`exhibition-${name}`].value = value;
  elements['studio-form'].dataset = {baseline: JSON.stringify(baseline)};
  elements['studio-form'].reportValidity = () => true;
  elements['studio-form'].action = 'http://127.0.0.1/studio.php?id=1';
  elements['studio-form'].elements = {namedItem: name => elements[name]};
  elements.revision.value = 'a'.repeat(32);
  elements['studio-form'].querySelectorAll = () => [
    ...Object.keys(baseline).map(name => elements[`exhibition-${name}`]),
    ...elements['content-editor'].querySelectorAll('input, textarea, select, button'),
  ];
  elements['save-status'].dataset = {state};
  elements['add-chapter'].disabled = true;
  elements['save-status'].textContent = state === 'error' ? 'Server save error; edits retained.' : 'Draft saved successfully.';
  const window = element();
  window.confirm = () => true;
  window.scrollX = 0;
  window.scrollY = 640;
  window.scrollTo = ({left, top, behavior}) => {
    assert.equal(behavior, 'instant');
    window.scrollX = left;
    window.scrollY = top;
  };
  const document = {getElementById: id => elements[id], createElement: tag => ({...element(), tag})};
  const requests = [];
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '..', 'assets', 'studio.js'), 'utf8'), {
    document, window, URL, TextEncoder, TypeError,
    FormData: class {
      constructor() {
        this.values = {...Object.fromEntries(Object.keys(baseline).map(name => [name, elements[`exhibition-${name}`].value])),
          revision: elements.revision.value};
      }
    },
    fetch: (url, options) => { requests.push({url, options}); return fetchImplementation(url, options); },
  });
  return {elements, window, document, requests};
}

function descendants(element) {
  return element.children.flatMap(child => [child, ...descendants(child)]);
}

function studioAction(elements, label) {
  const button = descendants(elements['content-editor']).find(child => child.tag === 'button' && child.textContent === label);
  assert.ok(button, `Missing action: ${label}`);
  assert.equal(button.disabled, false);
  button.listeners.click();
}

function studioInput(elements, label) {
  const field = descendants(elements['content-editor']).find(child => child.tag === 'label' && child.textContent === label);
  assert.ok(field, `Missing field: ${label}`);
  return field.children[0];
}

test('Studio adds, edits, reorders and removes chapters and blocks in the serialized save payload', () => {
  const {elements, window} = studioPage();
  const payload = () => JSON.parse(elements['exhibition-chapters_json'].value);
  elements['add-chapter'].listeners.click();
  assert.equal(elements['details-editor'].hidden, true);
  const title = studioInput(elements, 'Chapter title');
  title.value = 'A real chapter';
  title.listeners.input();
  studioAction(elements, 'Add text');
  const heading = studioInput(elements, 'Heading');
  heading.value = '<script>Plain text</script>';
  heading.listeners.input();
  studioAction(elements, 'Add artifact');
  studioAction(elements, 'Move block down');
  assert.deepEqual(payload()[0].blocks.map(block => block.type), ['artifact', 'text']);
  assert.equal(payload()[0].blocks[1].content.heading, '<script>Plain text</script>');
  assert.equal(elements['save-status'].dataset.state, 'unsaved');
  assert.equal(descendants(elements['pv-blocks']).some(child => child.textContent === '<script>Plain text</script>'), true);
  elements['add-chapter'].listeners.click();
  studioAction(elements, 'Move chapter up');
  assert.equal(payload()[1].title, 'A real chapter');
  studioAction(elements, 'Remove chapter');
  assert.equal(payload().length, 1);
  window.confirm = () => false;
  studioAction(elements, 'Remove block');
  assert.equal(payload()[0].blocks.length, 2);
  window.confirm = () => true;
  studioAction(elements, 'Remove block');
  assert.equal(payload()[0].blocks.length, 1);
  elements['edit-details'].listeners.click();
  assert.equal(elements['details-editor'].hidden, false);
  assert.equal(elements['content-editor'].hidden, true);
});

test('Studio restores persisted content, preserves IDs, validates figures and warns on content-only changes', () => {
  const chapters = [{id: '8', title: 'Saved chapter', era: 'Era', intro: 'Opening', blocks: [
    {id: '12', type: 'text', content: {heading: 'Saved heading', body: 'Saved paragraph'}},
  ]}];
  const {elements, window} = studioPage('saved', chapters);
  elements['chapter-list'].children[0].listeners.click();
  assert.equal(studioInput(elements, 'Heading').value, 'Saved heading');
  assert.equal(elements['pv-title'].textContent, 'Saved chapter');
  studioAction(elements, 'Add figure');
  let prevented = false;
  elements['studio-form'].listeners.submit({preventDefault() { prevented = true; }});
  assert.equal(prevented, true);
  assert.equal(elements['save-status'].dataset.state, 'error');
  const url = studioInput(elements, 'Image URL');
  url.value = 'https://example.test/image.jpg';
  url.listeners.input();
  const payload = JSON.parse(elements['exhibition-chapters_json'].value);
  assert.equal(payload[0].id, '8');
  assert.equal(payload[0].blocks[0].id, '12');
  assert.equal(descendants(elements['pv-blocks']).find(child => child.tag === 'img').referrerPolicy, 'no-referrer');
  let warned = false;
  window.listeners.beforeunload({preventDefault() { warned = true; }});
  assert.equal(warned, true);
});

test('Selecting and reverting saved content do not mark the draft dirty; metadata description previews safely', () => {
  const chapters = [{id: '8', title: 'Saved chapter', era: '', intro: '', blocks: []}];
  const {elements, window} = studioPage('saved', chapters);
  elements['chapter-list'].children[0].listeners.click();
  const title = studioInput(elements, 'Chapter title');
  title.value = 'Changed';
  title.listeners.input();
  title.value = 'Saved chapter';
  title.listeners.input();
  let warned = false;
  window.listeners.beforeunload({preventDefault() { warned = true; }});
  assert.equal(warned, false);
  elements['edit-details'].listeners.click();
  elements['exhibition-description'].value = '<img onerror="bad">';
  elements['exhibition-description'].listeners.input();
  assert.equal(elements['pv-blocks'].children[0].textContent, '<img onerror="bad">');
});

test('Malformed retained content is reported without replacing the submitted payload', () => {
  const {elements} = studioPage('error', {invalid: true});
  assert.match(elements['content-error'].textContent, /Nothing has been discarded/);
  assert.equal(elements['exhibition-chapters_json'].value, '{"invalid":true}');
  assert.equal(elements['add-chapter'].disabled, true);
  assert.equal(elements['save-status'].dataset.state, 'error');
});

test('Studio reveals invalid metadata before validating the selected chapter', () => {
  const {elements} = studioPage();
  elements['add-chapter'].listeners.click();
  studioInput(elements, 'Chapter title').value = '';
  studioInput(elements, 'Chapter title').listeners.input();
  elements['exhibition-title'].value = '';
  let prevented = false;
  elements['studio-form'].listeners.submit({preventDefault() { prevented = true; }});
  assert.equal(prevented, true);
  assert.equal(elements['details-editor'].hidden, false);
  assert.equal(elements['exhibition-title'].focused, true);
  assert.match(elements['save-status'].textContent, /exhibition title/);
});

test('Studio preserves preview toggle and starts from saved values', () => {
  const {elements} = studioPage();
  assert.equal(elements['pv-title'].textContent, 'Saved title');
  assert.equal(elements['save-status'].dataset.state, 'saved');
  elements['preview-toggle'].listeners.click();
  assert.equal(elements['studio-preview'].hidden, true);
  assert.equal(elements['preview-toggle'].attributes['aria-expanded'], 'false');
  elements['preview-toggle'].listeners.click();
  assert.equal(elements['studio-preview'].hidden, false);
  assert.equal(elements['preview-toggle'].textContent, 'Hide preview');
});

test('Studio previews all metadata safely, tracks unsaved/reverted edits and warns on leaving', () => {
  const {elements, window} = studioPage();
  elements['exhibition-title'].value = '<img src=x onerror=alert(1)>';
  elements['exhibition-summary'].value = 'Live introduction';
  elements['exhibition-subject'].value = 'Technology';
  elements['exhibition-cover'].value = 'https://example.test/image.jpg';
  elements['exhibition-title'].listeners.input();
  assert.equal(elements['pv-title'].textContent, '<img src=x onerror=alert(1)>');
  assert.equal(elements['project-title'].textContent, elements['pv-title'].textContent);
  assert.equal(elements['pv-intro'].textContent, 'Live introduction');
  assert.equal(elements['pv-eyebrow'].textContent, 'Technology');
  assert.equal(elements['pv-category'].textContent, 'Technology');
  assert.equal(elements['preview-orbit'].hidden, true);
  assert.match(elements['preview-art'].style.backgroundImage, /https:\/\/example.test/);
  assert.equal(elements['save-status'].dataset.state, 'unsaved');
  let warned = false;
  window.listeners.beforeunload({preventDefault() { warned = true; }});
  assert.equal(warned, true);
  for (const url of ['javascript:alert(1)', 'data:image/svg+xml,x', 'https://user:pass@example.test/a',
    '//example.test/a', 'https://example.test/a\nb', 'bad', `https://example.test/${'a'.repeat(2048)}`]) {
    elements['exhibition-cover'].value = url;
    elements['exhibition-cover'].listeners.input();
    assert.equal(elements['preview-art'].style.backgroundImage, '');
    assert.equal(elements['preview-orbit'].hidden, false);
  }
  for (const [name, value] of Object.entries(JSON.parse(elements['studio-form'].dataset.baseline))) {
    elements[`exhibition-${name}`].value = value;
  }
  elements['exhibition-title'].listeners.input();
  assert.equal(elements['save-status'].dataset.state, 'saved');
  warned = false;
  window.listeners.beforeunload({preventDefault() { warned = true; }});
  assert.equal(warned, false);
});

test('Studio validates, saves in place, blocks duplicate submit and protects in-flight edits', () => {
  const {elements, window} = studioPage();
  let prevented = false;
  const submit = () => elements['studio-form'].listeners.submit({preventDefault() { prevented = true; }});
  elements['exhibition-title'].value = ' ';
  submit();
  assert.equal(prevented, true);
  assert.equal(elements['save-status'].dataset.state, 'error');
  elements['exhibition-title'].value = 'Edited';
  elements['studio-form'].reportValidity = () => false;
  prevented = false;
  submit();
  assert.equal(prevented, true);
  elements['studio-form'].reportValidity = () => true;
  prevented = false;
  submit();
  assert.equal(prevented, true);
  assert.equal(elements['save-draft'].disabled, true);
  assert.equal(elements['save-status'].dataset.state, 'saving');
  let warned = false;
  window.listeners.beforeunload({preventDefault() { warned = true; }});
  assert.equal(warned, true);
  submit();
  assert.equal(prevented, true);
  window.listeners.pageshow();
  assert.equal(elements['save-draft'].disabled, true);
  assert.equal(elements['save-status'].dataset.state, 'saving');
});

test('Studio keeps server errors visible while previewing retained input and blocks conflicts', () => {
  const {elements} = studioPage('error');
  elements['exhibition-title'].value = 'Retained edit';
  elements['exhibition-title'].listeners.input();
  assert.equal(elements['pv-title'].textContent, 'Retained edit');
  assert.equal(elements['save-status'].textContent, 'Server save error; edits retained.');
  elements['save-draft'].disabled = true;
  let prevented = false;
  elements['studio-form'].listeners.submit({preventDefault() { prevented = true; }});
  assert.equal(prevented, true);
});

function jsonResponse(result, status = 200) {
  return {ok: status === 200, redirected: false, headers: {get: () => 'application/json'},
    json: async () => result};
}

function savedResponse(body, revision = 'b'.repeat(32)) {
  const values = {...body.values};
  const chapters = JSON.parse(values.chapters_json);
  chapters.forEach((chapter, index) => {
    chapter.id ??= String(index + 20);
    chapter.title = chapter.title.trim();
    chapter.blocks.forEach((block, blockIndex) => {
      block.id ??= String(index * 10 + blockIndex + 40);
      block.content = Object.fromEntries(Object.entries(block.content).map(([key, value]) => [key, value.trim()]));
    });
  });
  values.title = values.title.trim();
  values.chapters_json = JSON.stringify(chapters);
  return jsonResponse({values, revision, message: 'Draft saved successfully.'});
}

for (const selected of [-1, 0, 1]) {
  test(`In-place save retains ${selected === -1 ? 'Exhibition Details' : `chapter ${selected + 1}`}, block context, scroll and assigned IDs`, async () => {
    const chapters = [0, 1].map(index => ({id: null, title: `Chapter ${index + 1}`, era: '', intro: '', blocks: [
      {id: null, type: 'text', content: {heading: 'Heading', body: 'Body'}},
    ]}));
    let finish;
    const {elements, window, document, requests} = studioPage('saved', chapters,
      () => new Promise(resolve => { finish = resolve; }));
    if (selected !== -1) elements['chapter-list'].children[selected].listeners.click();
    const input = selected === -1 ? elements['exhibition-title'] : studioInput(elements, 'Body');
    input.value = ' Edited text ';
    input.listeners.input();
    document.activeElement = input;
    input.selectionStart = 4;
    input.selectionEnd = 7;
    elements['content-editor'].scrollTop = 230;
    const editorNodes = [...elements['content-editor'].children];
    const submit = elements['studio-form'].listeners.submit({preventDefault() {}});
    assert.equal(requests.length, 1);
    assert.equal(requests[0].options.headers.Accept, 'application/json');
    assert.equal(requests[0].options.credentials, 'same-origin');
    assert.equal(input.disabled, true);
    assert.equal(elements['save-status'].dataset.state, 'saving');
    elements['edit-details'].listeners.click();
    elements['chapter-list'].children[selected === 0 ? 1 : 0].listeners.click();
    assert.equal(elements['details-editor'].hidden, selected !== -1);
    finish(savedResponse(requests[0].options.body));
    await submit;
    assert.equal(elements['details-editor'].hidden, selected !== -1);
    assert.equal(elements['save-status'].textContent, 'Draft saved successfully.');
    assert.equal(elements['save-status'].dataset.state, 'saved');
    assert.equal(elements['save-draft'].disabled, false);
    assert.equal(input.disabled, false);
    assert.equal(input.focused, true);
    assert.equal(input.selectionStart, 4);
    assert.equal(input.selectionEnd, 7);
    assert.equal(input.value, 'Edited text');
    assert.equal(window.scrollY, 640);
    assert.equal(elements['content-editor'].scrollTop, 230);
    assert.deepEqual(elements['content-editor'].children, editorNodes);
    assert.equal(elements.revision.value, 'b'.repeat(32));
    const saved = JSON.parse(elements['exhibition-chapters_json'].value);
    assert.equal(saved[0].id, '20');
    assert.equal(saved[1].blocks[0].id, '50');
    window.listeners.beforeunload({preventDefault() { assert.fail('Saved draft must be clean'); }});
    input.value = 'Continue editing';
    input.listeners.input();
    assert.equal(elements['save-status'].dataset.state, 'unsaved');
    const secondSubmit = elements['studio-form'].listeners.submit({preventDefault() {}});
    assert.equal(requests[1].options.body.values.revision, 'b'.repeat(32));
    assert.equal(JSON.parse(requests[1].options.body.values.chapters_json)[0].id, '20');
    finish(savedResponse(requests[1].options.body, 'c'.repeat(32)));
    await secondSubmit;
    assert.equal(elements.revision.value, 'c'.repeat(32));
    window.listeners.beforeunload({preventDefault() { assert.fail('Second saved draft must be clean'); }});
  });
}

for (const status of [403, 422, 409, 503]) {
  test(`In-place ${status} failure preserves chapter and edits, reports errors and protects unsaved work`, async () => {
    const chapters = [{id: '8', title: 'Chapter', era: '', intro: '', blocks: []}];
    const {elements, window} = studioPage('saved', chapters, async () => jsonResponse({
      message: `Save failed (${status}); edits retained.`, errors: {title: 'Check this title'},
      editable: status !== 409,
    }, status));
    elements['chapter-list'].children[0].listeners.click();
    const title = studioInput(elements, 'Chapter title');
    title.value = 'Retained chapter';
    title.listeners.input();
    await elements['studio-form'].listeners.submit({preventDefault() {}});
    assert.equal(elements['details-editor'].hidden, true);
    assert.equal(studioInput(elements, 'Chapter title'), title);
    assert.equal(title.value, 'Retained chapter');
    assert.equal(elements['title-error'].textContent, 'Check this title');
    assert.equal(elements['save-status'].dataset.state, 'error');
    assert.match(elements['save-status'].textContent, /edits retained/);
    assert.equal(elements['save-draft'].disabled, status === 409);
    assert.equal(title.disabled, false);
    assert.equal(elements.revision.value, 'a'.repeat(32));
    let warned = false;
    window.listeners.beforeunload({preventDefault() { warned = true; }});
    assert.equal(warned, true);
  });
}

test('Network errors, expired sessions and malformed success responses never discard edits or mark them saved', async () => {
  for (const fetch of [
    async () => { throw new TypeError('Network failed'); },
    async () => ({redirected: true}),
    async () => jsonResponse({values: {}, revision: 'bad'}),
  ]) {
    const {elements, window} = studioPage('saved', [], fetch);
    elements['exhibition-title'].value = 'Retained details';
    await elements['studio-form'].listeners.submit({preventDefault() {}});
    assert.equal(elements['details-editor'].hidden, false);
    assert.equal(elements['exhibition-title'].value, 'Retained details');
    assert.equal(elements['save-status'].dataset.state, 'error');
    assert.equal(elements['save-draft'].disabled, false);
    assert.equal(elements.revision.value, 'a'.repeat(32));
    let warned = false;
    window.listeners.beforeunload({preventDefault() { warned = true; }});
    assert.equal(warned, true);
  }
});

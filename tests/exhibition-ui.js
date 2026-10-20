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
    listeners, attributes,
    addEventListener: (name, callback) => { listeners[name] = callback; },
    setAttribute: (name, value) => { attributes[name] = value; },
    focus() { this.focused = true; },
    classList: {
      add: name => classes.add(name),
      remove: name => classes.delete(name),
      toggle(name) {
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

function studioPage(state = 'saved') {
  const ids = ['preview-toggle', 'studio-layout', 'studio-preview', 'studio-form', 'save-status',
    'save-draft', 'exhibition-title', 'exhibition-summary', 'exhibition-subject', 'exhibition-cover',
    'pv-title', 'pv-intro', 'pv-eyebrow', 'pv-category', 'preview-art', 'preview-orbit', 'project-title'];
  const elements = Object.fromEntries(ids.map(id => [id, element()]));
  const baseline = {title: 'Saved title', summary: 'Saved intro', subject: 'History', cover: ''};
  for (const [name, value] of Object.entries(baseline)) elements[`exhibition-${name}`].value = value;
  elements['studio-form'].dataset = {baseline: JSON.stringify(baseline)};
  elements['studio-form'].reportValidity = () => true;
  elements['save-status'].dataset = {state};
  elements['save-status'].textContent = state === 'error' ? 'Server save error; edits retained.' : 'Draft saved successfully.';
  const window = element();
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '..', 'assets', 'studio.js'), 'utf8'), {
    document: {getElementById: id => elements[id]}, window, URL, TextEncoder, TypeError,
  });
  return {elements, window};
}

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

test('Studio validates, shows saving, blocks duplicate submit and restores after back navigation', () => {
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
  assert.equal(prevented, false);
  assert.equal(elements['save-draft'].disabled, true);
  assert.equal(elements['save-status'].dataset.state, 'saving');
  window.listeners.beforeunload({preventDefault() { assert.fail('Saving must allow navigation'); }});
  submit();
  assert.equal(prevented, true);
  window.listeners.pageshow();
  assert.equal(elements['save-draft'].disabled, false);
  assert.equal(elements['save-status'].dataset.state, 'unsaved');
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

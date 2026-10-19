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

test('Read-only Studio toggles preview without editor/sample initialization', () => {
  const elements = Object.fromEntries(['preview-toggle', 'studio-layout', 'studio-preview'].map(id => [id, element()]));
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '..', 'assets', 'studio.js'), 'utf8'), {
    document: {getElementById: id => {
      assert.ok(elements[id], 'Studio attempted to initialize an editing surface');
      return elements[id];
    }},
  });
  elements['preview-toggle'].listeners.click();
  assert.equal(elements['studio-preview'].hidden, true);
  assert.equal(elements['preview-toggle'].attributes['aria-expanded'], 'false');
  elements['preview-toggle'].listeners.click();
  assert.equal(elements['studio-preview'].hidden, false);
  assert.equal(elements['preview-toggle'].textContent, 'Hide preview');
});

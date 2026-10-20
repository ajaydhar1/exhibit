(() => {
  'use strict';
  const toggle = document.getElementById('preview-toggle');
  toggle.addEventListener('click', () => {
    const hidden = document.getElementById('studio-layout').classList.toggle('preview-hidden');
    document.getElementById('studio-preview').hidden = hidden;
    toggle.textContent = hidden ? 'Show preview' : 'Hide preview';
    toggle.setAttribute('aria-expanded', String(!hidden));
  });
  const form = document.getElementById('studio-form');
  const fields = Object.fromEntries(['title', 'summary', 'subject', 'cover', 'subtitle', 'description', 'chapters_json'].map(
    name => [name, document.getElementById(`exhibition-${name}`)]
  ));
  const baseline = JSON.parse(form.dataset.baseline);
  const status = document.getElementById('save-status');
  const button = document.getElementById('save-draft');
  const publishButton = document.getElementById('publish-exhibition');
  const publishForm = document.getElementById('publish-form');
  const publishRevision = document.getElementById('publish-revision');
  let initialMessage = status.textContent;
  let initialState = status.dataset.state;
  let submitting = false;
  let selected = -1;
  let editorFields = [];
  let chapters;
  try {
    chapters = JSON.parse(fields.chapters_json.value);
    if (!Array.isArray(chapters) || chapters.some(chapter => !chapter
      || typeof chapter.title !== 'string' || typeof chapter.era !== 'string'
      || typeof chapter.intro !== 'string' || !Array.isArray(chapter.blocks)
      || chapter.blocks.some(block => !block || !['text', 'artifact', 'figure'].includes(block.type)
        || !block.content || typeof block.content !== 'object' || Array.isArray(block.content)
        || Object.values(block.content).some(value => typeof value !== 'string')))) {
      throw new TypeError('Invalid chapter structure');
    }
  } catch (error) {
    if (!(error instanceof SyntaxError) && !(error instanceof TypeError)) throw error;
    document.getElementById('content-error').textContent =
      'Content could not be opened for editing. Copy your unsaved metadata, then reopen Studio. Nothing has been discarded.';
    chapters = null;
  }
  if (chapters !== null) {
    fields.chapters_json.value = JSON.stringify(chapters);
    baseline.chapters_json = JSON.stringify(JSON.parse(baseline.chapters_json));
  }
  const dirty = () => Object.entries(fields).some(([name, field]) => field.value !== baseline[name]);
  const setStatus = (state, message) => {
    status.dataset.state = state;
    status.textContent = message;
    if (publishButton) publishButton.disabled = button.disabled || submitting || dirty() || state !== 'saved';
  };
  const changed = () => {
    preview();
    if (initialState === 'error') {
      setStatus('error', initialMessage);
    } else {
      setStatus(dirty() ? 'unsaved' : 'saved', dirty() ? 'Unsaved changes' : initialMessage);
    }
  };
  const node = (tag, text, className) => {
    const result = document.createElement(tag);
    if (text !== undefined) result.textContent = text;
    if (className) result.className = className;
    return result;
  };
  const action = (text, callback, disabled = false) => {
    const result = node('button', text, 'studio-btn studio-btn-subtle');
    result.type = 'button';
    result.disabled = disabled || button.disabled;
    result.addEventListener('click', callback);
    return result;
  };
  const syncContent = () => {
    fields.chapters_json.value = JSON.stringify(chapters);
    renderList();
    changed();
  };
  const choose = index => {
    if (submitting) return;
    selected = index;
    document.getElementById('details-editor').hidden = selected !== -1;
    document.getElementById('content-editor').hidden = selected === -1;
    document.getElementById('editor-title').textContent = selected === -1 ? 'Exhibition details' : 'Chapter editor';
    renderList();
    renderEditor();
    preview();
  };
  const renderList = () => {
    if (chapters === null) return;
    const list = document.getElementById('chapter-list');
    list.replaceChildren();
    const details = document.getElementById('edit-details');
    details.classList.toggle('active', selected === -1);
    details.setAttribute('aria-current', String(selected === -1));
    if (chapters.length === 0) list.append(node('p', 'No chapters yet. Add a chapter to begin.', 'editor-note'));
    chapters.forEach((chapter, index) => {
      const choice = action(`${String(index + 1).padStart(2, '0')} / ${chapter.title || 'Untitled chapter'}`, () => choose(index));
      choice.className = `chapter-choice${selected === index ? ' active' : ''}`;
      choice.disabled = false;
      choice.setAttribute('aria-current', String(selected === index));
      list.append(choice);
    });
  };
  const editorField = (parent, label, read, limit, update, multiline = false, required = false, url = false) => {
    const wrapper = node('label', label);
    const input = node(multiline ? 'textarea' : 'input');
    if (!multiline) input.type = url ? 'url' : 'text';
    else input.rows = 4;
    input.value = read();
    editorFields.push({input, read});
    input.maxLength = limit;
    input.required = required;
    input.readOnly = button.disabled;
    input.addEventListener('input', () => {
      update(input.value);
      syncContent();
    });
    wrapper.append(input);
    parent.append(wrapper);
  };
  const move = (items, index, direction) => {
    [items[index], items[index + direction]] = [items[index + direction], items[index]];
  };
  const renderEditor = () => {
    const editor = document.getElementById('content-editor');
    editor.replaceChildren();
    editorFields = [];
    if (selected === -1) return;
    const chapter = chapters[selected];
    editor.append(node('p', `CHAPTER ${selected + 1}`, 'chapter-overline'));
    const controls = node('div', undefined, 'block-actions');
    controls.append(
      action('Move chapter up', () => { move(chapters, selected, -1); selected--; syncContent(); choose(selected); }, selected === 0),
      action('Move chapter down', () => { move(chapters, selected, 1); selected++; syncContent(); choose(selected); }, selected === chapters.length - 1),
      action('Remove chapter', () => {
        if (!window.confirm('Remove this chapter and all its content? Save Draft to make this permanent.')) return;
        chapters.splice(selected, 1);
        selected = Math.min(selected, chapters.length - 1);
        syncContent();
        choose(selected);
      })
    );
    editor.append(controls);
    editorField(editor, 'Chapter title', () => chapter.title, 110, value => { chapter.title = value; }, false, true);
    editorField(editor, 'Era or subtitle (optional)', () => chapter.era, 100, value => { chapter.era = value; });
    editorField(editor, 'Opening narrative', () => chapter.intro, 2000, value => { chapter.intro = value; }, true);
    editor.append(node('p', `CHAPTER CONTENT / ${chapter.blocks.length} BLOCKS`, 'editor-divider'));
    chapter.blocks.forEach((block, index) => {
      const card = node('div', undefined, 'block-card');
      card.append(node('p', `${block.type.toUpperCase()} / ${index + 1}`, 'block-card-head'));
      const controls = node('div', undefined, 'block-actions');
      controls.append(
        action('Move block up', () => { move(chapter.blocks, index, -1); syncContent(); renderEditor(); }, index === 0),
        action('Move block down', () => { move(chapter.blocks, index, 1); syncContent(); renderEditor(); }, index === chapter.blocks.length - 1),
        action('Remove block', () => {
          if (!window.confirm('Remove this block? Save Draft to make this permanent.')) return;
          chapter.blocks.splice(index, 1);
          syncContent();
          renderEditor();
        })
      );
      card.append(controls);
      const limits = block.type === 'figure'
        ? {url: 2048, alt: 320, caption: 2000, credit: 320} : {heading: 130, body: 2000};
      for (const [key, limit] of Object.entries(limits)) {
        editorField(card, key === 'url' ? 'Image URL' : key[0].toUpperCase() + key.slice(1),
          () => block.content[key] ?? '', limit, value => { block.content[key] = value; },
          key === 'body' || key === 'caption', key === 'url', key === 'url');
      }
      editor.append(card);
    });
    const add = node('div', undefined, 'block-actions');
    for (const type of ['text', 'artifact', 'figure']) {
      add.append(action(`Add ${type}`, () => {
        chapter.blocks.push({id: null, type, content: type === 'figure'
          ? {url: '', alt: '', caption: '', credit: ''} : {heading: '', body: ''}});
        syncContent();
        renderEditor();
      }));
    }
    editor.append(add);
  };
  const validImage = value => {
    try {
      const url = new URL(value);
      return /^https?:\/\//.test(value) && ['http:', 'https:'].includes(url.protocol)
        && !url.username && !url.password && !/[\x00-\x20\x7f]/.test(value)
        && new TextEncoder().encode(value).length <= 2048;
    } catch (error) {
      if (!(error instanceof TypeError)) throw error;
      return false;
    }
  };
  const preview = () => {
    const title = fields.title.value.trim() || 'Your exhibition title';
    const subject = fields.subject.value || 'YOUR EXHIBITION';
    document.getElementById('pv-title').textContent = title;
    document.getElementById('project-title').textContent = title;
    document.getElementById('pv-intro').textContent = fields.summary.value.trim() || 'No introduction yet.';
    document.getElementById('pv-eyebrow').textContent = subject;
    document.getElementById('pv-category').textContent = subject;
    const cover = fields.cover.value.trim();
    const valid = validImage(cover);
    const art = document.getElementById('preview-art');
    art.style.backgroundImage = valid ? `url(${JSON.stringify(cover)}), linear-gradient(135deg, #183f38, #326e5d)` : '';
    art.style.backgroundSize = valid ? 'cover' : '';
    art.style.backgroundPosition = valid ? 'center' : '';
    document.getElementById('preview-orbit').hidden = valid;
    const blocks = document.getElementById('pv-blocks');
    blocks.replaceChildren();
    if (selected === -1) {
      if (fields.subtitle.value) blocks.append(node('h4', fields.subtitle.value));
      if (fields.description.value) blocks.append(node('p', fields.description.value, 'preview-intro'));
    } else {
      const chapter = chapters[selected];
      document.getElementById('pv-title').textContent = chapter.title || 'Untitled chapter';
      document.getElementById('pv-intro').textContent = chapter.intro || 'No opening narrative yet.';
      document.getElementById('pv-eyebrow').textContent = `CHAPTER ${selected + 1} / ${chapter.era || 'YOUR STORY'}`;
      chapter.blocks.forEach(block => {
        const card = node('div', undefined, `preview-block${block.type === 'artifact' ? ' preview-artifact' : ''}`);
        if (block.type === 'figure') {
          if (validImage((block.content.url ?? '').trim())) {
            const image = node('img');
            image.src = block.content.url.trim();
            image.alt = block.content.alt || '';
            image.referrerPolicy = 'no-referrer';
            card.append(image);
          }
          card.append(node('p', block.content.caption || ''), node('small', block.content.credit || ''));
        } else {
          card.append(node('h4', block.content.heading || ''), node('p', block.content.body || ''));
        }
        blocks.append(card);
      });
    }
  };
  Object.values(fields).forEach(field => field.addEventListener('input', changed));
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (submitting || button.disabled) {
      return;
    }
    // Reveal invalid metadata before native validation tries to focus a hidden field.
    if (!fields.title.value.trim()
      || Object.values(fields).some(field => field.willValidate && !field.validity.valid)) {
      choose(-1);
      form.reportValidity();
      if (!fields.title.value.trim()) fields.title.focus();
      setStatus('error', !fields.title.value.trim()
        ? 'Add an exhibition title before saving.' : 'Check the exhibition details before saving.');
      return;
    }
    if (chapters !== null) {
      const invalidChapter = chapters.findIndex(chapter => !chapter.title.trim()
        || chapter.blocks.some(block => block.type === 'figure' && !validImage((block.content.url ?? '').trim())));
      if (invalidChapter !== -1) {
        choose(invalidChapter);
        form.reportValidity();
        setStatus('error', 'Check the chapter title and figure URLs before saving.');
        return;
      }
    }
    if (!form.reportValidity()) {
      setStatus('error', 'Check the chapter fields before saving.');
      return;
    }
    submitting = true;
    const body = new FormData(form);
    const controls = [...form.querySelectorAll('input, textarea, select, button'), document.getElementById('add-chapter')];
    const disabled = controls.map(control => control.disabled);
    const active = document.activeElement;
    const selection = active && typeof active.selectionStart === 'number'
      ? [active.selectionStart, active.selectionEnd] : null;
    const scroll = [window.scrollX, window.scrollY];
    const panes = ['content-editor', 'details-editor', 'studio-preview'].map(id => document.getElementById(id));
    const paneScroll = panes.map(pane => [pane.scrollLeft, pane.scrollTop]);
    controls.forEach(control => { control.disabled = true; });
    button.disabled = true;
    setStatus('saving', 'Saving draft...');
    let editable = true;
    let failureMessage = 'Save could not be confirmed. Check your connection; your edits have been retained.';
    try {
      const response = await fetch(form.action, {
        method: 'POST', body, headers: {Accept: 'application/json'}, credentials: 'same-origin',
      });
      if (response.redirected || !response.headers.get('Content-Type')?.includes('application/json')) {
        failureMessage = 'Your session may have expired or this draft is unavailable. Copy your edits before reopening Studio.';
        throw new Error(failureMessage);
      }
      const result = await response.json();
      if (!response.ok) {
        editable = result.editable !== false;
        for (const name of Object.keys(fields)) {
          const error = document.getElementById(name === 'chapters_json' ? 'content-error' : `${name}-error`);
          if (error) error.textContent = result.errors?.[name === 'chapters_json' ? 'content' : name] || '';
          fields[name].setAttribute('aria-invalid', String(Boolean(result.errors?.[name])));
        }
        failureMessage = result.message || 'Save failed. Your edits have been retained.';
        throw new Error(failureMessage);
      }
      const savedChapters = JSON.parse(result.values.chapters_json);
      if (!/^[a-f0-9]{32}$/.test(result.revision) || !Array.isArray(savedChapters) || chapters === null
        || chapters.length !== savedChapters.length
        || chapters.some((chapter, index) => !savedChapters[index]
          || (chapter.id !== null && chapter.id !== savedChapters[index].id)
          || chapter.blocks.length !== savedChapters[index].blocks?.length
          || chapter.blocks.some((block, blockIndex) => {
            const saved = savedChapters[index].blocks[blockIndex];
            return !saved || block.type !== saved.type || (block.id !== null && block.id !== saved.id);
          }))
        || Object.keys(fields).some(name => typeof result.values[name] !== 'string')) {
        failureMessage = 'The save could not be confirmed. Your edits have been retained; reopen Studio to review the saved version.';
        throw new Error(failureMessage);
      }
      // Keep the editor nodes and their closures, updating canonical values and new IDs in place.
      chapters.forEach((chapter, index) => {
        const saved = savedChapters[index];
        chapter.id = saved.id;
        chapter.title = saved.title;
        chapter.era = saved.era;
        chapter.intro = saved.intro;
        chapter.blocks.forEach((block, blockIndex) => Object.assign(block, saved.blocks[blockIndex]));
      });
      result.values.chapters_json = JSON.stringify(chapters);
      for (const [name, field] of Object.entries(fields)) {
        field.value = result.values[name];
        baseline[name] = result.values[name];
        const error = document.getElementById(name === 'chapters_json' ? 'content-error' : `${name}-error`);
        if (error) error.textContent = '';
        field.setAttribute('aria-invalid', 'false');
      }
      editorFields.forEach(({input, read}) => { input.value = read(); });
      form.elements.namedItem('revision').value = result.revision;
      if (publishRevision) publishRevision.value = result.revision;
      initialState = 'saved';
      initialMessage = result.message;
      renderList();
      preview();
      setStatus('saved', result.message);
    } catch (error) {
      setStatus('error', failureMessage);
    } finally {
      submitting = false;
      controls.forEach((control, index) => { control.disabled = disabled[index]; });
      button.disabled = !editable;
      if (publishButton) publishButton.disabled = !editable || dirty() || status.dataset.state !== 'saved';
      if (active && !active.disabled) {
        active.focus({preventScroll: true});
        if (selection) active.setSelectionRange(...selection);
      }
      panes.forEach((pane, index) => {
        [pane.scrollLeft, pane.scrollTop] = paneScroll[index];
      });
      window.scrollTo({left: scroll[0], top: scroll[1], behavior: 'instant'});
    }
  });
  document.getElementById('edit-details').addEventListener('click', () => choose(-1));
  if (publishForm) {
    publishForm.addEventListener('submit', event => {
      if (submitting || dirty() || button.disabled || status.dataset.state !== 'saved') {
        event.preventDefault();
        setStatus('error', 'Save your changes successfully before publishing.');
      }
    });
  }
  if (chapters !== null) {
    const addChapter = document.getElementById('add-chapter');
    addChapter.disabled = button.disabled;
    addChapter.addEventListener('click', () => {
      chapters.push({id: null, title: 'Untitled chapter', era: '', intro: '', blocks: []});
      syncContent();
      choose(chapters.length - 1);
    });
    renderList();
  }
  window.addEventListener('beforeunload', event => {
    if (dirty()) {
      event.preventDefault();
      event.returnValue = '';
    }
  });
  window.addEventListener('pageshow', () => {
    if (submitting) return;
    if (initialState !== 'error') {
      setStatus(dirty() ? 'unsaved' : initialState, dirty() ? 'Unsaved changes' : initialMessage);
    }
    preview();
  });
  preview();
})();

(function () {
  const root = document.getElementById('admin-ai-studio');
  const configNode = document.getElementById('admin-ai-studio-config');

  if (!root || !configNode) {
    return;
  }

  let config = null;
  try {
    config = JSON.parse(configNode.textContent || '{}');
  } catch (error) {
    console.error('Failed to parse AI studio config', error);
    return;
  }

  const state = {
    currentFileListPath: (config.fileRoots && config.fileRoots[0] && config.fileRoots[0].path) || '/',
    currentImageListPath: (config.imageRoots && config.imageRoots[0] && config.imageRoots[0].path) || '/storage/uploads/site',
    currentFilePath: '',
    aiDraftContent: '',
    lastImagePath: '',
  };

  const nodes = {
    fileRoot: document.getElementById('studio-file-root'),
    fileRootRefresh: document.getElementById('studio-file-root-refresh'),
    fileStatus: document.getElementById('studio-file-status'),
    fileList: document.getElementById('studio-file-list'),
    filePath: document.getElementById('studio-file-path'),
    fileMeta: document.getElementById('studio-file-meta'),
    openFile: document.getElementById('studio-open-file'),
    saveStatus: document.getElementById('studio-save-status'),
    editor: document.getElementById('studio-editor'),
    saveFile: document.getElementById('studio-save-file'),
    aiInstruction: document.getElementById('studio-ai-instruction'),
    aiStatus: document.getElementById('studio-ai-status'),
    aiSummary: document.getElementById('studio-ai-summary'),
    aiRewrite: document.getElementById('studio-ai-rewrite'),
    aiApply: document.getElementById('studio-ai-apply'),
    imageRoot: document.getElementById('studio-image-root'),
    imageRootRefresh: document.getElementById('studio-image-root-refresh'),
    imageStatus: document.getElementById('studio-image-status'),
    imageList: document.getElementById('studio-image-list'),
    imagePath: document.getElementById('studio-image-path'),
    imagePrompt: document.getElementById('studio-image-prompt'),
    imageSize: document.getElementById('studio-image-size'),
    imageQuality: document.getElementById('studio-image-quality'),
    imageFormat: document.getElementById('studio-image-format'),
    imageBackground: document.getElementById('studio-image-background'),
    imageReplace: document.getElementById('studio-image-replace'),
    imageRunStatus: document.getElementById('studio-image-run-status'),
    imageRun: document.getElementById('studio-image-run'),
    imageCopy: document.getElementById('studio-image-copy'),
    imageInsert: document.getElementById('studio-image-insert'),
    imageResultMeta: document.getElementById('studio-image-result-meta'),
    imagePreview: document.getElementById('studio-image-preview'),
  };

  const imageExtensions = new Set(['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'avif', 'ico']);

  function fillSelect(selectNode, items, preferredPath) {
    if (!selectNode || !Array.isArray(items)) {
      return;
    }
    selectNode.innerHTML = '';
    items.forEach((item) => {
      const option = document.createElement('option');
      option.value = item.path;
      option.textContent = item.label;
      if (item.path === preferredPath) {
        option.selected = true;
      }
      selectNode.appendChild(option);
    });
  }

  function setStatus(node, message, tone) {
    if (!node) {
      return;
    }
    node.textContent = message;
    node.className = 'admin-status';
    if (tone) {
      node.classList.add(`admin-status--${tone}`);
    }
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function normalizePath(value) {
    const normalized = String(value || '').trim().replace(/\\/g, '/').replace(/\/+/g, '/');
    if (!normalized) {
      return '';
    }
    return normalized.startsWith('/') ? normalized : `/${normalized}`;
  }

  function parentPath(path) {
    const normalized = normalizePath(path);
    if (!normalized || normalized === '/') {
      return null;
    }
    const parts = normalized.split('/').filter(Boolean);
    parts.pop();
    return parts.length ? `/${parts.join('/')}` : '/';
  }

  async function fetchJson(url, options) {
    const response = await fetch(url, Object.assign({ credentials: 'same-origin' }, options || {}));
    const text = await response.text();
    let data = {};

    try {
      data = text ? JSON.parse(text) : {};
    } catch (error) {
      throw new Error(`Invalid JSON response (${response.status})`);
    }

    if (!response.ok || data.success !== true) {
      throw new Error(data.error || `HTTP ${response.status}`);
    }

    return data;
  }

  function renderBrowserList(node, items, options) {
    const { currentPath, onOpenDirectory, onOpenFile, filter } = options;
    node.innerHTML = '';

    const upPath = parentPath(currentPath);
    if (upPath !== null) {
      const upButton = document.createElement('button');
      upButton.type = 'button';
      upButton.className = 'admin-browser-item admin-browser-item--directory';
      upButton.innerHTML = '<strong>..</strong><span>Повернутись вище</span>';
      upButton.addEventListener('click', () => onOpenDirectory(upPath));
      node.appendChild(upButton);
    }

    const filtered = Array.isArray(items) ? items.filter(filter) : [];
    if (filtered.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'admin-empty';
      empty.textContent = 'Тут немає відповідних файлів.';
      node.appendChild(empty);
      return;
    }

    filtered.forEach((item) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = `admin-browser-item admin-browser-item--${item.type === 'directory' ? 'directory' : 'file'}`;
      button.innerHTML = `<strong>${escapeHtml(item.name)}</strong><span>${escapeHtml(item.path)}</span>`;

      if (item.type === 'directory') {
        button.addEventListener('click', () => onOpenDirectory(item.path));
      } else {
        button.addEventListener('click', () => onOpenFile(item.path));
      }

      node.appendChild(button);
    });
  }

  async function loadFileList(path) {
    state.currentFileListPath = normalizePath(path) || '/';
    setStatus(nodes.fileStatus, `Завантаження: ${state.currentFileListPath}`, 'info');

    try {
      const data = await fetchJson(`${config.filesApi}?action=list&path=${encodeURIComponent(state.currentFileListPath)}`);
      renderBrowserList(nodes.fileList, data.items || [], {
        currentPath: state.currentFileListPath,
        onOpenDirectory: loadFileList,
        onOpenFile: openFile,
        filter(item) {
          return item.type === 'directory' || item.editable === true;
        },
      });
      setStatus(nodes.fileStatus, `Папка: ${state.currentFileListPath}`, 'success');
    } catch (error) {
      setStatus(nodes.fileStatus, error.message || 'Не вдалося завантажити список файлів.', 'error');
      nodes.fileList.innerHTML = '';
    }
  }

  async function loadImageList(path) {
    state.currentImageListPath = normalizePath(path) || '/storage/uploads/site';
    setStatus(nodes.imageStatus, `Завантаження: ${state.currentImageListPath}`, 'info');

    try {
      const data = await fetchJson(`${config.filesApi}?action=list&path=${encodeURIComponent(state.currentImageListPath)}`);
      renderBrowserList(nodes.imageList, data.items || [], {
        currentPath: state.currentImageListPath,
        onOpenDirectory: loadImageList,
        onOpenFile: selectImage,
        filter(item) {
          return item.type === 'directory' || imageExtensions.has(String(item.extension || '').toLowerCase());
        },
      });
      setStatus(nodes.imageStatus, `Папка: ${state.currentImageListPath}`, 'success');
    } catch (error) {
      setStatus(nodes.imageStatus, error.message || 'Не вдалося завантажити список фото.', 'error');
      nodes.imageList.innerHTML = '';
    }
  }

  async function openFile(path) {
    const safePath = normalizePath(path || nodes.filePath.value);
    if (!safePath) {
      setStatus(nodes.saveStatus, 'Вкажіть шлях до файлу.', 'error');
      return;
    }

    setStatus(nodes.saveStatus, `Відкриваю ${safePath}…`, 'info');

    try {
      const data = await fetchJson(`${config.filesApi}?action=read&path=${encodeURIComponent(safePath)}`);
      state.currentFilePath = safePath;
      nodes.filePath.value = safePath;
      nodes.editor.value = data.content || '';
      nodes.fileMeta.textContent = `Розмір: ${data.size || 0} байт • Оновлено: ${data.modified || 'невідомо'}`;
      setStatus(nodes.saveStatus, 'Файл відкрито. Можна редагувати або запускати AI.', 'success');
    } catch (error) {
      setStatus(nodes.saveStatus, error.message || 'Не вдалося відкрити файл.', 'error');
    }
  }

  async function saveCurrentFile() {
    const path = normalizePath(nodes.filePath.value || state.currentFilePath);
    if (!path) {
      setStatus(nodes.saveStatus, 'Спочатку відкрийте файл.', 'error');
      return;
    }

    setStatus(nodes.saveStatus, 'Збереження файлу…', 'info');
    nodes.saveFile.disabled = true;

    try {
      await fetchJson(config.filesApi, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'save',
          path,
          content: nodes.editor.value,
          csrf_token: config.csrfToken,
        }),
      });
      state.currentFilePath = path;
      setStatus(nodes.saveStatus, 'Файл успішно збережено.', 'success');
      nodes.fileMeta.textContent = `Розмір: ${nodes.editor.value.length} символів • Збережено щойно`;
    } catch (error) {
      setStatus(nodes.saveStatus, error.message || 'Не вдалося зберегти файл.', 'error');
    } finally {
      nodes.saveFile.disabled = false;
    }
  }

  async function rewriteWithAi() {
    const path = normalizePath(nodes.filePath.value || state.currentFilePath);
    const instruction = nodes.aiInstruction.value.trim();

    if (!path) {
      setStatus(nodes.aiStatus, 'Спочатку відкрийте файл для AI-редагування.', 'error');
      return;
    }
    if (!nodes.editor.value.trim()) {
      setStatus(nodes.aiStatus, 'У редакторі порожньо. AI не має з чим працювати.', 'error');
      return;
    }
    if (!instruction) {
      setStatus(nodes.aiStatus, 'Опишіть завдання для AI.', 'error');
      return;
    }

    nodes.aiRewrite.disabled = true;
    nodes.aiApply.disabled = true;
    setStatus(nodes.aiStatus, 'AI готує нову версію файлу…', 'info');

    try {
      const data = await fetchJson(config.aiApi, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'rewrite_html',
          path,
          content: nodes.editor.value,
          instruction,
          csrf_token: config.csrfToken,
        }),
      });

      state.aiDraftContent = data.content || '';
      nodes.aiSummary.textContent = data.summary || 'AI підготував варіант оновлення.';
      nodes.aiApply.disabled = !state.aiDraftContent;
      setStatus(nodes.aiStatus, `Готово. Модель: ${data.model || 'невідомо'}. Перевірте результат і підставте його в редактор.`, 'success');
    } catch (error) {
      setStatus(nodes.aiStatus, error.message || 'AI не зміг підготувати новий варіант.', 'error');
      nodes.aiSummary.textContent = 'AI не повернув коректний результат.';
    } finally {
      nodes.aiRewrite.disabled = false;
    }
  }

  function applyAiDraft() {
    if (!state.aiDraftContent) {
      return;
    }
    nodes.editor.value = state.aiDraftContent;
    setStatus(nodes.saveStatus, 'AI-версію підставлено в редактор. Тепер можна зберегти файл.', 'success');
  }

  function selectImage(path) {
    const safePath = normalizePath(path);
    if (!safePath) {
      return;
    }

    nodes.imagePath.value = safePath;
    nodes.imageResultMeta.textContent = `Вибрано джерело: ${safePath}`;
    nodes.imagePreview.hidden = false;
    nodes.imagePreview.src = `${safePath}?v=${Date.now()}`;
    nodes.imagePreview.alt = safePath;
    setStatus(nodes.imageRunStatus, 'Зображення вибрано. Тепер можна запускати AI.', 'success');
  }

  async function runImageAi() {
    const payload = {
      action: 'generate_image',
      prompt: nodes.imagePrompt.value.trim(),
      source_path: normalizePath(nodes.imagePath.value),
      size: nodes.imageSize.value,
      quality: nodes.imageQuality.value,
      output_format: nodes.imageFormat.value,
      background: nodes.imageBackground.value,
      replace_source: nodes.imageReplace.checked,
      csrf_token: config.csrfToken,
    };

    if (!payload.prompt) {
      setStatus(nodes.imageRunStatus, 'Опишіть, яке зображення потрібно отримати.', 'error');
      return;
    }

    nodes.imageRun.disabled = true;
    nodes.imageCopy.disabled = true;
    nodes.imageInsert.disabled = true;
    setStatus(nodes.imageRunStatus, 'AI обробляє зображення… Це може зайняти до хвилини.', 'info');

    try {
      const data = await fetchJson(config.aiApi, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });

      state.lastImagePath = data.path || '';
      if (state.lastImagePath) {
        nodes.imagePath.value = state.lastImagePath;
        nodes.imagePreview.hidden = false;
        nodes.imagePreview.src = data.url || `${state.lastImagePath}?v=${Date.now()}`;
        nodes.imagePreview.alt = state.lastImagePath;
      }
      nodes.imageResultMeta.textContent = data.message || `Збережено: ${data.path || ''}`;
      nodes.imageCopy.disabled = !state.lastImagePath;
      nodes.imageInsert.disabled = !state.lastImagePath;
      setStatus(nodes.imageRunStatus, `${data.message || 'AI завершив роботу.'} Файл: ${data.path || 'невідомо'}`, 'success');

      if (payload.replace_source && payload.source_path) {
        loadImageList(parentPath(payload.source_path) || state.currentImageListPath);
      } else {
        loadImageList(state.currentImageListPath);
      }
    } catch (error) {
      setStatus(nodes.imageRunStatus, error.message || 'Не вдалося отримати зображення від AI.', 'error');
    } finally {
      nodes.imageRun.disabled = false;
    }
  }

  async function copyLastImagePath() {
    if (!state.lastImagePath) {
      return;
    }

    try {
      await navigator.clipboard.writeText(state.lastImagePath);
      setStatus(nodes.imageRunStatus, 'Шлях до зображення скопійовано.', 'success');
    } catch (error) {
      setStatus(nodes.imageRunStatus, 'Не вдалося скопіювати шлях у буфер.', 'error');
    }
  }

  function insertTextAtCursor(textarea, value) {
    const start = textarea.selectionStart || 0;
    const end = textarea.selectionEnd || 0;
    const original = textarea.value;
    textarea.value = `${original.slice(0, start)}${value}${original.slice(end)}`;
    textarea.focus();
    const nextPos = start + value.length;
    textarea.setSelectionRange(nextPos, nextPos);
  }

  function insertLastImagePathIntoEditor() {
    if (!state.lastImagePath) {
      return;
    }
    insertTextAtCursor(nodes.editor, state.lastImagePath);
    setStatus(nodes.saveStatus, 'Шлях до нового зображення вставлено в редактор.', 'success');
  }

  function bindEvents() {
    nodes.fileRoot.addEventListener('change', () => loadFileList(nodes.fileRoot.value));
    nodes.fileRootRefresh.addEventListener('click', () => loadFileList(nodes.fileRoot.value));
    nodes.openFile.addEventListener('click', () => openFile(nodes.filePath.value));
    nodes.saveFile.addEventListener('click', saveCurrentFile);
    nodes.aiRewrite.addEventListener('click', rewriteWithAi);
    nodes.aiApply.addEventListener('click', applyAiDraft);
    nodes.imageRoot.addEventListener('change', () => loadImageList(nodes.imageRoot.value));
    nodes.imageRootRefresh.addEventListener('click', () => loadImageList(nodes.imageRoot.value));
    nodes.imageRun.addEventListener('click', runImageAi);
    nodes.imageCopy.addEventListener('click', copyLastImagePath);
    nodes.imageInsert.addEventListener('click', insertLastImagePathIntoEditor);
  }

  fillSelect(nodes.fileRoot, config.fileRoots || [], state.currentFileListPath);
  fillSelect(nodes.imageRoot, config.imageRoots || [], state.currentImageListPath);
  bindEvents();
  loadFileList(state.currentFileListPath);
  loadImageList(state.currentImageListPath);
})();

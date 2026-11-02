(function () {
  const contentSelector = '.layout > .content';
  const sidebarSelector = '.layout > .sidebar';
  let latestRequest = 0;
  let navigationController = null;

  const progress = document.createElement('div');
  progress.className = 'ajax-page-progress';
  progress.setAttribute('aria-hidden', 'true');
  document.body.appendChild(progress);

  function isAdminPage(url) {
    return url.origin === window.location.origin &&
      url.pathname.startsWith('/admin/') &&
      url.pathname.endsWith('.php');
  }

  function setLoading(loading) {
    document.body.classList.toggle('ajax-page-loading', loading);
    progress.setAttribute('aria-hidden', String(!loading));
  }

  function showMessage(type, message) {
    const content = document.querySelector(contentSelector);
    if (!content) return;

    content.querySelectorAll('.ajax-request-message').forEach((item) => item.remove());
    const alert = document.createElement('div');
    alert.className = `alert alert-${type} ajax-request-message`;
    alert.setAttribute('role', type === 'danger' ? 'alert' : 'status');
    alert.textContent = message;
    content.prepend(alert);
  }

  async function executePageScripts(content) {
    const scripts = Array.from(content.querySelectorAll('script'));
    for (const script of scripts) {
      const replacement = document.createElement('script');
      Array.from(script.attributes).forEach(({ name, value }) => replacement.setAttribute(name, value));
      replacement.textContent = script.textContent;
      if (replacement.src) {
        const loaded = new Promise((resolve, reject) => {
          replacement.addEventListener('load', resolve, { once: true });
          replacement.addEventListener('error', () => reject(new Error(`Impossible de charger ${replacement.src}`)), { once: true });
        });
        script.replaceWith(replacement);
        await loaded;
      } else {
        script.replaceWith(replacement);
      }
    }
  }

  async function renderPage(url, options) {
    const requestId = ++latestRequest;
    const isNavigation = options.method === 'GET';
    if (navigationController) navigationController.abort();
    navigationController = isNavigation ? new AbortController() : null;
    setLoading(true);

    try {
      const response = await fetch(url.href, {
        method: options.method,
        body: options.body,
        credentials: 'same-origin',
        headers: {
          Accept: 'text/html, application/json',
          'X-Requested-With': 'XMLHttpRequest',
          ...(options.body && !(options.body instanceof FormData) ? { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' } : {})
        },
        signal: navigationController?.signal
      });

      const contentType = response.headers.get('content-type') || '';
      if (contentType.includes('application/json')) {
        const result = await response.json();
        if (!response.ok || result.ok === false) {
          throw new Error(result.error || result.message || 'La demande a échoué.');
        }
        if (requestId !== latestRequest) return;
        await renderPage(new URL(window.location.href), { method: 'GET', history: 'replace' });
        if (result.message) showMessage('success', result.message);
        return;
      }

      if (!response.ok) {
        if (isNavigation) {
          window.location.assign(url.href);
          return;
        }
        throw new Error(`Le serveur a répondu avec le statut ${response.status}.`);
      }

      const html = await response.text();
      const nextDocument = new DOMParser().parseFromString(html, 'text/html');
      const nextContent = nextDocument.querySelector(contentSelector);
      const nextSidebar = nextDocument.querySelector(sidebarSelector);
      const currentContent = document.querySelector(contentSelector);
      const currentSidebar = document.querySelector(sidebarSelector);

      if (!nextContent || !nextSidebar || !currentContent || !currentSidebar) {
        if (isNavigation || !isAdminPage(new URL(response.url))) {
          window.location.assign(response.url);
          return;
        }
        throw new Error('La réponse du serveur ne contient pas la page attendue.');
      }
      if (requestId !== latestRequest) return;

      const destination = new URL(response.url);
      if (options.history === 'push' || (options.history === 'form' && destination.href !== window.location.href)) {
        window.history.replaceState({ ...(window.history.state || {}), scrollY: window.scrollY }, '', window.location.href);
        window.history.pushState({ ajaxPage: true, scrollY: 0 }, '', destination.href);
      } else if (options.history === 'replace' || options.history === 'form') {
        window.history.replaceState({ ajaxPage: true, scrollY: window.scrollY }, '', destination.href);
      }

      currentContent.replaceWith(nextContent);
      currentSidebar.replaceWith(nextSidebar);
      document.title = nextDocument.title;
      await executePageScripts(nextContent);
      document.dispatchEvent(new CustomEvent('botora:page-loaded'));
      if (options.restoreScroll !== undefined) {
        window.scrollTo(0, options.restoreScroll);
      } else if (options.method === 'GET') {
        window.scrollTo(0, 0);
      }
    } catch (error) {
      if (error.name === 'AbortError' || requestId !== latestRequest) return;
      if (isNavigation) {
        window.location.assign(url.href);
      } else {
        showMessage('danger', error.message || 'La demande n’a pas pu être envoyée. Vérifiez votre connexion puis réessayez.');
      }
    } finally {
      if (requestId === latestRequest) {
        setLoading(false);
        navigationController = null;
      }
    }
  }

  document.addEventListener('click', (event) => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    if (!(event.target instanceof Element)) return;
    const link = event.target.closest('a[href]');
    if (!link || link.hasAttribute('download') || (link.target && link.target !== '_self')) return;

    const url = new URL(link.href, window.location.href);
    if (!isAdminPage(url) || url.href === window.location.href || url.hash && url.pathname === window.location.pathname && url.search === window.location.search) return;

    event.preventDefault();
    renderPage(url, { method: 'GET', history: 'push' });
  });

  document.addEventListener('submit', (event) => {
    if (event.defaultPrevented) return;
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !document.querySelector(contentSelector)?.contains(form)) return;
    const submitter = event.submitter;
    const target = submitter?.formTarget || form.target;
    if (form.dataset.ajax === 'false' || (target && target !== '_self')) return;

    const method = (submitter?.formMethod || form.method || 'GET').toUpperCase();
    const action = submitter?.formAction || form.action || window.location.href;
    const url = new URL(action, window.location.href);
    if (!isAdminPage(url) || !['GET', 'POST'].includes(method)) return;

    event.preventDefault();
    const body = method === 'POST'
      ? (submitter ? new FormData(form, submitter) : new FormData(form))
      : undefined;
    if (method === 'GET') {
      const params = new URLSearchParams();
      new FormData(form).forEach((value, key) => {
        if (typeof value === 'string') params.append(key, value);
      });
      url.search = params.toString();
    }

    const button = submitter instanceof HTMLElement ? submitter : form.querySelector('[type="submit"]');
    const wasDisabled = button?.disabled;
    if (button) {
      button.disabled = true;
      button.setAttribute('aria-busy', 'true');
    }
    form.setAttribute('aria-busy', 'true');
    renderPage(url, { method, body, history: 'form' }).finally(() => {
      if (button) {
        button.disabled = wasDisabled;
        button.removeAttribute('aria-busy');
      }
      form.removeAttribute('aria-busy');
    });
  });

  window.addEventListener('popstate', (event) => {
    renderPage(new URL(window.location.href), {
      method: 'GET',
      history: 'none',
      restoreScroll: event.state?.scrollY || 0
    });
  });

  window.BotoraAdmin = {
    navigate(url, replace) {
      const destination = new URL(url, window.location.href);
      if (!isAdminPage(destination)) {
        window.location.assign(destination.href);
        return;
      }
      renderPage(destination, { method: 'GET', history: replace ? 'replace' : 'push' });
    },
    refresh() {
      renderPage(new URL(window.location.href), { method: 'GET', history: 'replace' });
    }
  };
})();
